<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning an approved campaign into messages.
 *
 * Everything expensive is behind this one method. The rails are here rather than
 * in the controller because a scheduled send does not pass through a controller,
 * and a guard that only runs on the button is not a guard.
 */
class CampaignSender
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly MessageMeter $meter,
    ) {}

    /**
     * What this campaign would cost and reach, without sending anything.
     *
     * @return array{
     *     recipient_count: int,
     *     effective_recipient_count: int,
     *     seed_mode: bool,
     *     characters: int,
     *     segments: int,
     *     encoding: string,
     *     non_gsm_characters: array<int, string>,
     *     estimated_cost: float,
     *     cap: int,
     *     over_cap: bool
     * }
     */
    public function preview(Campaign $campaign): array
    {
        $measurement = $this->meter->measure($campaign->message);

        $audienceSize = $this->audienceSize($campaign);
        $recipients = $this->recipientsFor($campaign);
        $effective = count($recipients);

        $cap = $this->recipientCap();

        return [
            // What the segment actually holds, always reported honestly even in
            // seed mode — the operator needs to see the real reach next to the
            // handful of numbers about to be messaged.
            'recipient_count' => $audienceSize,
            'effective_recipient_count' => $effective,
            'seed_mode' => $this->seedMode(),

            'characters' => $measurement['characters'],
            'segments' => $measurement['segments'],
            'encoding' => $measurement['encoding'],
            'non_gsm_characters' => $measurement['non_gsm_characters'],

            'estimated_cost' => $this->meter->estimateCost($campaign->message, $effective),

            // 0 means there is no cap. The frontend already reads it that way,
            // so an open console reports the same shape as a capped one rather
            // than needing a second field to say "ignore the number above".
            'cap' => $cap,
            'over_cap' => $this->overCap($audienceSize),
        ];
    }

    /**
     * Send it.
     *
     * Claims the campaign with a conditional UPDATE before doing any work, so
     * two operators pressing approve at the same moment cannot blast the list
     * twice. Everything after the claim is queued.
     *
     * @throws RuntimeException when a rail refuses the send
     */
    public function send(Campaign $campaign, User $approver): Campaign
    {
        $this->assertWithinSendWindow();

        $recipients = $this->recipientsFor($campaign);

        if ($recipients === []) {
            throw new RuntimeException('Nobody is in that segment right now, so there is nothing to send.');
        }

        if ($this->overCap(count($recipients))) {
            throw new RuntimeException(
                'That segment holds '.number_format(count($recipients)).' people, over the '.
                number_format($this->recipientCap()).
                ' limit for one campaign. Raise the limit deliberately, or pick a narrower segment.'
            );
        }

        $measurement = $this->meter->measure($campaign->message);

        // The claim. A conditional UPDATE is already atomic and the count it
        // returns is the claim: whichever approver arrives second changes
        // nothing and is told so, rather than both passing a read-then-write
        // check and sending the campaign twice.
        $claimed = Campaign::whereKey($campaign->id)
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value])
            ->update([
                'status' => CampaignStatus::Sending->value,
                'approved_by_user_id' => $approver->id,
                'started_at' => now(),
                'recipient_count' => count($recipients),
                'segments_per_message' => $measurement['segments'],
                'estimated_cost' => $this->meter->estimateCost($campaign->message, count($recipients)),
                'sent_count' => 0,
                'failed_count' => 0,
                'actual_cost' => null,
            ]);

        if (! $claimed) {
            throw new RuntimeException('This campaign has already been sent.');
        }

        $this->dispatchChunks($campaign->fresh(), $recipients);

        return $campaign->fresh();
    }

    /**
     * Fold one chunk's outcome into the permanent totals.
     *
     * Atomic increments rather than read-modify-write, because chunks finish
     * concurrently and a lost update here is a campaign that never reports as
     * complete.
     */
    public function recordChunkResult(
        int $campaignId,
        int $sent,
        int $failed,
        ?float $cost = null,
        ?string $batchId = null,
    ): void {
        DB::transaction(function () use ($campaignId, $sent, $failed, $cost, $batchId) {
            $campaign = Campaign::whereKey($campaignId)->lockForUpdate()->first();

            if (! $campaign) {
                return;
            }

            $campaign->sent_count += $sent;
            $campaign->failed_count += $failed;

            if ($batchId !== null) {
                // Appended rather than replaced: a campaign is many chunks, and
                // each one is a batch the poll has to ask about separately.
                $campaign->batch_ids = array_values(array_unique([
                    ...(array) ($campaign->batch_ids ?? []),
                    $batchId,
                ]));
            }

            if ($cost !== null) {
                // Null until the first chunk reports a real rate, so an unknown
                // cost stays visibly unknown rather than reading as free.
                $campaign->actual_cost = (float) ($campaign->actual_cost ?? 0) + $cost;
            }

            if ($campaign->isFinished()) {
                $campaign->completed_at = now();
                // Failed only when nothing at all got through. A campaign that
                // reached most of the list is a campaign that happened, and
                // calling it "failed" would hide that from the report.
                $campaign->status = $campaign->sent_count > 0
                    ? CampaignStatus::Sent
                    : CampaignStatus::Failed;
            }

            $campaign->save();
        });
    }

    /**
     * The numbers this campaign will actually be sent to.
     *
     * In seed mode that is the fixed staff list, whatever segment was picked.
     * This is how the whole mechanism gets proven for a few cedis rather than
     * four figures, and it is why the preview reports both counts.
     *
     * @return array<int, string>
     */
    public function recipientsFor(Campaign $campaign): array
    {
        if ($this->seedMode()) {
            return array_values(array_unique(array_filter(
                array_map(
                    fn (string $phone) => $this->toHubtelFormat($phone),
                    (array) config('campaigns.seed_list', []),
                ),
            )));
        }

        return array_values(array_unique(array_filter(
            array_map(
                fn (string $phone) => $this->toHubtelFormat($phone),
                $this->audienceFor($campaign),
            ),
        )));
    }

    /**
     * The phone numbers this campaign's audience resolves to, right now.
     *
     * Assembled rules win over the preset when both are present — the preset is
     * only ever the starting point the operator narrowed from, and its label is
     * kept for the list. Resolved fresh at send time rather than read off the
     * draft, because a segment written last week is not the segment being sent
     * to today.
     *
     * @return array<int, string>
     */
    public function audienceFor(Campaign $campaign): array
    {
        $rules = $campaign->rules();

        return $rules->isEmpty()
            ? $this->audience->phones($campaign->segment)
            : $this->audience->phonesForRules($rules);
    }

    /** How many people the audience holds, however it was described. */
    public function audienceSize(Campaign $campaign): int
    {
        $rules = $campaign->rules();

        return $rules->isEmpty()
            ? $this->audience->count($campaign->segment)
            : $this->audience->countRules($rules);
    }

    /**
     * The most people one campaign may reach, or 0 for no limit.
     *
     * 0 is the default and the ordinary case. There was a 2,000 ceiling here
     * until the whole customer base became the point rather than the accident;
     * config/campaigns.php has the reasoning and the env value that puts a
     * figure back.
     */
    public function recipientCap(): int
    {
        return max(0, (int) config('campaigns.recipient_cap', 0));
    }

    /**
     * Whether this many people is more than one campaign is allowed to reach.
     *
     * False whenever the cap is 0, and false in seed mode whatever the audience
     * holds — seed mode texts the staff list, so the size of the segment behind
     * it is a reported figure and not a bill.
     */
    public function overCap(int $audienceSize): bool
    {
        $cap = $this->recipientCap();

        return $cap > 0 && ! $this->seedMode() && $audienceSize > $cap;
    }

    public function seedMode(): bool
    {
        // Through RuntimeSettings, not config(), so the platform toggle actually
        // governs it. A `.env` change would not reach the queue workers until
        // somebody SSHed in and restarted them — and the send runs in a worker,
        // so the toggle would appear to do nothing exactly where it matters.
        //
        // Still defaults to TRUE the whole way down: every fallback in this
        // chain errs towards nobody being texted.
        return (bool) app(\App\Services\Platform\RuntimeSettings::class)
            ->get('campaigns.seed_mode');
    }

    /**
     * Refuse a send outside the configured hours or on a blocked day.
     *
     * Enforced here rather than in the controller because a scheduled send never
     * touches a controller.
     *
     * BY DEFAULT THIS PERMITS EVERYTHING — any hour, any day. Both restrictions
     * it used to impose were guesses about what is polite and both were wrong
     * here: Sunday, which it blocked, is the busiest sales day of the week, and
     * the 8am–7pm window was the same kind of assumption. When to reach
     * customers is a business decision, and refusing it as a validation error
     * nobody thinks to question is not where that decision belongs.
     *
     * The mechanism stays so the decision can be made in config later — see
     * config/campaigns.php for why the guard stays enabled rather than being
     * switched off.
     *
     * @throws RuntimeException
     */
    public function assertWithinSendWindow(): void
    {
        $window = (array) config('campaigns.send_window', []);

        if (! ($window['enabled'] ?? true)) {
            return;
        }

        $now = now();
        // Defaults match the config: wide open, so a missing key can never be
        // the reason a campaign is refused.
        $start = (int) ($window['start_hour'] ?? 0);
        $end = (int) ($window['end_hour'] ?? 24);

        if (in_array($now->isoWeekday(), (array) ($window['blocked_days'] ?? []), true)) {
            // Names the actual day rather than assuming which one is blocked —
            // the list is configurable, and a message that says "Sunday" on a
            // Monday is worse than no message.
            throw new RuntimeException(
                'Campaigns do not go out on a '.$now->format('l').'. Schedule it for another day.'
            );
        }

        if ($now->hour < $start || $now->hour >= $end) {
            throw new RuntimeException(
                "Campaigns go out between {$start}:00 and {$end}:00. Schedule it for the morning."
            );
        }
    }

    /**
     * Break the list into chunks and queue them.
     *
     * Chunks are spaced apart so a campaign arrives as a stream rather than one
     * spike, and so a rejected chunk costs a thousand recipients rather than the
     * whole list.
     *
     * @param  array<int, string>  $recipients
     */
    private function dispatchChunks(Campaign $campaign, array $recipients): void
    {
        $chunkSize = max(1, (int) config('campaigns.chunk_size', 1000));
        $delay = max(0, (int) config('campaigns.inter_batch_delay_seconds', 5));

        foreach (array_chunk($recipients, $chunkSize) as $index => $chunk) {
            SendCampaignChunk::dispatch($campaign->id, $chunk, $campaign->message)
                ->delay(now()->addSeconds($index * $delay));
        }
    }

    /**
     * Hubtel wants 233XXXXXXXXX — no plus, no leading zero.
     *
     * The audience is stored as +233…, which HubtelSmsService rejects outright.
     * Returns an empty string for anything that does not convert, and the caller
     * filters those out rather than sending a malformed number and recording a
     * failure for it.
     */
    private function toHubtelFormat(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233'.substr($digits, 1);
        }

        return strlen($digits) === 12 && str_starts_with($digits, '233') ? $digits : '';
    }
}
