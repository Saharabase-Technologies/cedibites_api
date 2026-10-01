<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\GhanaNetwork;
use App\Enums\SmsFailureReason;
use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\User;
use App\Notifications\CampaignPausedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
        private readonly CampaignLedger $ledger,
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

            // Numbers in the audience that no network would carry, mostly
            // placeholders typed at the till. They are left off the send, and
            // said here so the gap between the audience and the send is not a
            // mystery. Nothing to report in seed mode, where the send is the
            // staff list whatever the audience holds.
            'left_out_count' => $this->seedMode() ? 0 : max(0, $audienceSize - $effective),

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

        $campaign = $campaign->fresh();

        // The list, written down before the first request leaves. A send that
        // stops half way can only be picked up again from a record of who was
        // on it.
        $this->ledger->open($campaign, $recipients);

        // The claim above is a query, so the model never fires and nothing
        // logs it. Sending is the one act here that spends money. It gets its
        // own line, with a name on it.
        activity('admin')
            ->causedBy($approver)
            ->performedOn($campaign)
            ->event('campaign_sent')
            ->withProperties([
                'recipients' => count($recipients),
                'estimated_cost' => (float) $campaign->estimated_cost,
                'tested' => $campaign->last_tested_at !== null,
            ])
            ->log('Campaign "'.$campaign->name.'" sent to '.number_format(count($recipients)).' people');

        $this->dispatchChunks($campaign, $recipients);

        return $campaign->fresh();
    }

    /**
     * Send to the people who were missed, and nobody else.
     *
     * Works on a campaign that was paused, and on one that finished with part
     * of its list refused. The recipients come off the ledger, so the audience
     * is not resolved again and nobody Hubtel already accepted is texted twice.
     *
     * People we got no answer about are left out unless `$includeUnsure` is
     * set. They may have the message already, and only a person can decide
     * that the risk of a second copy is worth it.
     *
     * @throws RuntimeException when there is nothing to send or it is already going
     */
    public function resume(Campaign $campaign, User $by, bool $includeUnsure = false): Campaign
    {
        $this->assertWithinSendWindow();

        if (! $campaign->status->isResumable()) {
            throw new RuntimeException('This campaign has nothing waiting to be sent.');
        }

        $recipients = $this->ledger->resumable($campaign->id, $includeUnsure);

        if ($recipients === []) {
            throw new RuntimeException('Everybody on this campaign who can be sent to has been sent to.');
        }

        // The same kind of claim as a first send, for the same reason. Two
        // people pressing the button together must not both get through.
        $claimed = Campaign::whereKey($campaign->id)
            ->whereIn('status', [
                CampaignStatus::Paused->value,
                CampaignStatus::PartlySent->value,
                CampaignStatus::Failed->value,
            ])
            ->update([
                'status' => CampaignStatus::Sending->value,
                'paused_at' => null,
                'pause_reason' => null,
                'completed_at' => null,
            ]);

        if (! $claimed) {
            throw new RuntimeException('This campaign is already sending.');
        }

        // Anyone refused or unanswered was counted as failed when it happened.
        // They are about to be tried again, so they come back off that total.
        $wereFailed = $this->ledger->requeue($campaign->id, $recipients);

        if ($wereFailed > 0) {
            Campaign::whereKey($campaign->id)->update([
                'failed_count' => DB::raw('CASE WHEN failed_count > '.$wereFailed.' THEN failed_count - '.$wereFailed.' ELSE 0 END'),
            ]);
        }

        $campaign = $campaign->fresh();

        activity('admin')
            ->causedBy($by)
            ->performedOn($campaign)
            ->event('campaign_resumed')
            ->withProperties(['recipients' => count($recipients), 'included_unsure' => $includeUnsure])
            ->log('Campaign "'.$campaign->name.'" sent to the '.number_format(count($recipients)).' people who were missed');

        $this->dispatchChunks($campaign, $recipients);

        return $campaign->fresh();
    }

    /**
     * Stop a campaign where it is, and keep the rest of the list unsent.
     *
     * Called when Hubtel refuses a chunk for a reason the next chunk would meet
     * too. Until this existed, a campaign short of credit sent all seven of its
     * chunks into the same refusal and recorded 3,500 people as failed.
     *
     * A conditional UPDATE, so of several chunks failing together only the
     * first pauses and only one alert goes out.
     */
    public function pause(int $campaignId, SmsFailureReason $reason): bool
    {
        $paused = Campaign::whereKey($campaignId)
            ->where('status', CampaignStatus::Sending->value)
            ->update([
                'status' => CampaignStatus::Paused->value,
                'paused_at' => now(),
                'pause_reason' => $reason->value,
            ]);

        if (! $paused) {
            return false;
        }

        $campaign = Campaign::find($campaignId);

        // Warning, not error. An error lands on the fault feed and is texted to
        // the tech admin, and a text about SMS being out of credit is the one
        // alert that cannot be trusted to arrive. This goes by email.
        Log::warning('Campaign paused', [
            'campaign_id' => $campaignId,
            'reason' => $reason->value,
            'sent' => $campaign?->sent_count,
            'recipients' => $campaign?->recipient_count,
        ]);

        if ($campaign) {
            $this->announcePause($campaign, $reason);
        }

        return true;
    }

    /** Whether chunks for this campaign should still be going out. */
    public function isSending(int $campaignId): bool
    {
        return Campaign::whereKey($campaignId)
            ->where('status', CampaignStatus::Sending->value)
            ->exists();
    }

    /**
     * Tell the people who can do something about it.
     *
     * Whoever pressed send, plus whoever can read system health. Never allowed
     * to fail the job that called it: a campaign that paused and told nobody is
     * bad, a campaign whose pause threw is worse.
     */
    private function announcePause(Campaign $campaign, SmsFailureReason $reason): void
    {
        try {
            try {
                $recipients = User::permission('view_system_health')->get();
            } catch (\Throwable) {
                // The permission is not seeded here. The sender still hears.
                $recipients = collect();
            }

            if ($campaign->approvedBy) {
                $recipients = $recipients->push($campaign->approvedBy)->unique('id');
            }

            $notification = new CampaignPausedNotification($campaign, $reason);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, $notification);
            }

            foreach (array_filter(array_map('trim', explode(',', (string) config('services.sms.alert_emails', '')))) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Notification::route('mail', $email)->notify($notification);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Campaign pause alert could not be sent', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
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
                // Three endings, not two. Failed only when nothing at all got
                // through. Sent only when everything did. A campaign that
                // reached part of its list is neither, and calling it Sent is
                // how 39 of 3,539 showed as a green tick.
                $campaign->status = match (true) {
                    $campaign->sent_count >= $campaign->recipient_count => CampaignStatus::Sent,
                    $campaign->sent_count > 0 => CampaignStatus::PartlySent,
                    default => CampaignStatus::Failed,
                };
                $campaign->paused_at = null;
                $campaign->pause_reason = null;
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
     *
     * A number has to sit on a prefix a Ghana network uses. One in ten of the
     * numbers on the first production campaign did not (098…, 087…, typed at
     * the till when a customer gave none). They were counted in the audience
     * and priced into the quote, and no network would ever carry them.
     */
    private function toHubtelFormat(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233'.substr($digits, 1);
        }

        if (strlen($digits) !== 12 || ! str_starts_with($digits, '233')) {
            return '';
        }

        return GhanaNetwork::forPhone($digits) !== null ? $digits : '';
    }
}
