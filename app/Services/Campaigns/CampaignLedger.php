<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignRecipientState;
use App\Enums\GhanaNetwork;
use App\Enums\SmsFailureReason;
use App\Models\Campaign;
use App\Models\CampaignDelivery;
use App\Models\CampaignRecipient;
use App\Models\SmsDeliveryAttempt;
use Illuminate\Support\Facades\DB;

/**
 * The list a campaign was sent to, one row per person, and where each one is.
 *
 * Everything that lets a campaign be picked up again reads from here: a pause
 * that holds the unsent chunks, a resume that sends only to the people who were
 * missed, and the report that says how many were refused and why.
 *
 * Jobs speak Hubtel's format (233XXXXXXXXX) and the table holds ours
 * (+233XXXXXXXXX). The conversion happens at this class's edge and nowhere else.
 */
class CampaignLedger
{
    /**
     * Write the list down before anything is sent.
     *
     * insertOrIgnore, so opening the same campaign twice cannot double a row.
     *
     * @param  array<int, string>  $hubtelPhones
     */
    public function open(Campaign $campaign, array $hubtelPhones): void
    {
        $now = now();

        foreach (array_chunk($hubtelPhones, 1000) as $slice) {
            CampaignRecipient::insertOrIgnore(array_map(fn (string $phone) => [
                'campaign_id' => $campaign->id,
                'phone' => $this->stored($phone),
                'state' => CampaignRecipientState::Queued->value,
                'created_at' => $now,
                'updated_at' => $now,
            ], $slice));
        }
    }

    public function hasRows(int $campaignId): bool
    {
        return CampaignRecipient::where('campaign_id', $campaignId)->exists();
    }

    /**
     * Take a chunk's queued rows for sending, and say which ones were taken.
     *
     * The claim is what makes a chunk safe to run twice. A second job for the
     * same people finds nothing queued and sends nothing, where without it a
     * retried job would text the whole chunk again.
     *
     * @param  array<int, string>  $hubtelPhones
     * @return array<int, string> Hubtel format, only the rows this call claimed
     */
    public function claim(int $campaignId, array $hubtelPhones): array
    {
        return DB::transaction(function () use ($campaignId, $hubtelPhones) {
            $claimed = [];

            foreach (array_chunk(array_map($this->stored(...), $hubtelPhones), 500) as $slice) {
                $queued = CampaignRecipient::where('campaign_id', $campaignId)
                    ->whereIn('phone', $slice)
                    ->where('state', CampaignRecipientState::Queued->value)
                    ->lockForUpdate()
                    ->pluck('phone')
                    ->all();

                if ($queued === []) {
                    continue;
                }

                CampaignRecipient::where('campaign_id', $campaignId)
                    ->whereIn('phone', $queued)
                    ->update([
                        'state' => CampaignRecipientState::Sending->value,
                        'attempts' => DB::raw('attempts + 1'),
                        'attempted_at' => now(),
                        'updated_at' => now(),
                    ]);

                array_push($claimed, ...$queued);
            }

            return array_map($this->hubtel(...), $claimed);
        });
    }

    /**
     * Record how a claimed chunk ended.
     *
     * Only touches rows still in Sending, so it cannot overwrite an outcome
     * another job has already written for the same person.
     *
     * @param  array<int, string>  $hubtelPhones
     */
    public function settle(
        int $campaignId,
        array $hubtelPhones,
        CampaignRecipientState $state,
        ?SmsFailureReason $reason = null,
        ?string $batchId = null,
    ): int {
        $settled = 0;

        foreach (array_chunk(array_map($this->stored(...), $hubtelPhones), 500) as $slice) {
            $settled += CampaignRecipient::where('campaign_id', $campaignId)
                ->whereIn('phone', $slice)
                ->where('state', CampaignRecipientState::Sending->value)
                ->update([
                    'state' => $state->value,
                    'failure_reason' => $reason?->value,
                    'batch_id' => $batchId,
                    'updated_at' => now(),
                ]);
        }

        return $settled;
    }

    /**
     * The people a resume would send to, in Hubtel's format.
     *
     * Everybody still queued and everybody Hubtel clearly refused. People we
     * got no answer about are left out unless asked for, because they may have
     * the message already.
     *
     * A number refused as invalid is never sent to again. Sending it a second
     * time costs the same and fails the same.
     *
     * @return array<int, string>
     */
    public function resumable(int $campaignId, bool $includeUnsure = false): array
    {
        return $this->resumableQuery($campaignId, $includeUnsure)
            ->orderBy('id')
            ->pluck('phone')
            ->map($this->hubtel(...))
            ->all();
    }

    /** How many people a resume would send to. */
    public function resumableCount(int $campaignId, bool $includeUnsure = false): int
    {
        return $this->resumableQuery($campaignId, $includeUnsure)->count();
    }

    private function resumableQuery(int $campaignId, bool $includeUnsure)
    {
        return CampaignRecipient::where('campaign_id', $campaignId)
            ->resumable($includeUnsure);
    }

    /**
     * Put people back in the queue for another send.
     *
     * @param  array<int, string>  $hubtelPhones
     * @return int How many of them had been counted as failed, so the caller
     *             can take them back off the campaign's failed total
     */
    public function requeue(int $campaignId, array $hubtelPhones): int
    {
        $wereFailed = 0;

        foreach (array_chunk(array_map($this->stored(...), $hubtelPhones), 500) as $slice) {
            $query = fn () => CampaignRecipient::where('campaign_id', $campaignId)->whereIn('phone', $slice);

            $wereFailed += $query()
                ->whereIn('state', [
                    CampaignRecipientState::Refused->value,
                    CampaignRecipientState::Unsure->value,
                ])
                ->count();

            $query()->update([
                'state' => CampaignRecipientState::Queued->value,
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
        }

        return $wereFailed;
    }

    /**
     * Rows a dead job left in Sending become Unsure.
     *
     * The request may or may not have reached Hubtel before the worker was
     * killed, and nothing on our side can tell which.
     *
     * @param  array<int, string>  $hubtelPhones
     */
    public function abandon(int $campaignId, array $hubtelPhones): int
    {
        return $this->settle(
            $campaignId,
            $hubtelPhones,
            CampaignRecipientState::Unsure,
            SmsFailureReason::Unknown,
        );
    }

    /**
     * Where the list stands, in the five figures the campaign page shows.
     *
     * A campaign sent before this table existed has no rows, and is answered
     * from the counters on the campaign itself. `tracked` says which it was.
     *
     * @return array{
     *     tracked: bool, audience: int, accepted: int, refused: int,
     *     unsure: int, waiting: int, not_mobile: int, delivered: int
     * }
     */
    public function reach(Campaign $campaign): array
    {
        $counts = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->all();

        $get = fn (CampaignRecipientState $s): int => (int) ($counts[$s->value] ?? 0);

        if ($counts === []) {
            return [
                'tracked' => false,
                'audience' => (int) $campaign->recipient_count,
                'accepted' => (int) $campaign->sent_count,
                'refused' => (int) $campaign->failed_count,
                'unsure' => 0,
                'waiting' => max(0, $campaign->recipient_count - $campaign->accountedFor()),
                'not_mobile' => 0,
                'delivered' => (int) $campaign->delivered_count,
            ];
        }

        $notMobile = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('state', CampaignRecipientState::Refused->value)
            ->where('failure_reason', SmsFailureReason::InvalidRecipient->value)
            ->count();

        return [
            'tracked' => true,
            'audience' => array_sum($counts),
            'accepted' => $get(CampaignRecipientState::Accepted),
            // Kept apart from the numbers nobody can send to, which are a
            // fault in our list and not something Hubtel did.
            'refused' => $get(CampaignRecipientState::Refused) - $notMobile,
            'unsure' => $get(CampaignRecipientState::Unsure),
            'waiting' => $get(CampaignRecipientState::Queued) + $get(CampaignRecipientState::Sending),
            'not_mobile' => $notMobile,
            'delivered' => (int) $campaign->delivered_count,
        ];
    }

    /**
     * Why people were not sent to, most common first.
     *
     * @return array<int, array{reason: string, label: string, remedy: string, count: int}>
     */
    public function reasons(Campaign $campaign): array
    {
        return CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotNull('failure_reason')
            ->where('state', '!=', CampaignRecipientState::Accepted->value)
            ->selectRaw('failure_reason, count(*) as total')
            ->groupBy('failure_reason')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                $reason = $row->failure_reason instanceof SmsFailureReason
                    ? $row->failure_reason
                    : SmsFailureReason::from((string) $row->failure_reason);

                return [
                    'reason' => $reason->value,
                    'label' => $reason->label(),
                    'remedy' => $reason->remedy(),
                    'count' => (int) $row->total,
                ];
            })
            ->all();
    }

    /**
     * The list split by network, with how much of each was accepted and
     * delivered.
     *
     * Read off the prefix, so a ported number counts under the network that
     * issued it. See GhanaNetwork.
     *
     * @return array<int, array{value: string, label: string, sent_to: int, accepted: int, delivered: int}>
     */
    public function networks(Campaign $campaign): array
    {
        $rows = [];

        $bump = function (?string $phone, string $key) use (&$rows): void {
            $network = GhanaNetwork::forPhone($phone);
            $value = $network?->value ?? 'other';

            $rows[$value] ??= [
                'value' => $value,
                'label' => $network?->label() ?? 'Not a mobile number',
                'sent_to' => 0,
                'accepted' => 0,
                'delivered' => 0,
            ];

            $rows[$value][$key]++;
        };

        $tracked = false;

        CampaignRecipient::where('campaign_id', $campaign->id)
            ->select('id', 'phone', 'state')
            ->chunkById(2000, function ($recipients) use ($bump, &$tracked): void {
                $tracked = true;

                foreach ($recipients as $recipient) {
                    $bump($recipient->phone, 'sent_to');

                    if ($recipient->state === CampaignRecipientState::Accepted) {
                        $bump($recipient->phone, 'accepted');
                    }
                }
            });

        CampaignDelivery::where('campaign_id', $campaign->id)
            ->select('id', 'phone', 'outcome')
            ->chunkById(2000, function ($deliveries) use ($bump, $tracked): void {
                foreach ($deliveries as $delivery) {
                    // Without a ledger the delivery rows are the only list of
                    // who was accepted, so they stand in for it.
                    if (! $tracked) {
                        $bump($delivery->phone, 'sent_to');
                        $bump($delivery->phone, 'accepted');
                    }

                    if ($delivery->outcome === \App\Enums\DeliveryOutcome::Delivered) {
                        $bump($delivery->phone, 'delivered');
                    }
                }
            });

        usort($rows, fn (array $a, array $b) => $b['sent_to'] <=> $a['sent_to']);

        return array_values($rows);
    }

    /**
     * Rebuild the list for a campaign that went out before this table existed.
     *
     * Read from `sms_delivery_attempts`, which is pruned after thirty days, so
     * this only works while those rows are still there. Every failed row is
     * classified again from the error it stored, because a batch refused for
     * want of credit was filed as an unrecognised error at the time.
     *
     * A row is written as Refused only when what was stored reads as a clear
     * refusal. Anything else is Unsure.
     *
     * @return array{written: int, accepted: int, refused: int, unsure: int, not_mobile: int}
     */
    public function backfillFromAttempts(Campaign $campaign): array
    {
        $tally = ['written' => 0, 'accepted' => 0, 'refused' => 0, 'unsure' => 0, 'not_mobile' => 0];

        SmsDeliveryAttempt::where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->chunkById(1000, function ($attempts) use ($campaign, &$tally): void {
                $rows = [];

                foreach ($attempts as $attempt) {
                    $phone = $this->stored((string) $attempt->recipient);

                    [$state, $reason] = $this->readAttempt($attempt, $phone);

                    $rows[$phone] = [
                        'campaign_id' => $campaign->id,
                        'phone' => $phone,
                        'state' => $state->value,
                        'failure_reason' => $reason?->value,
                        'attempts' => 1,
                        'attempted_at' => $attempt->created_at,
                        'created_at' => $attempt->created_at,
                        'updated_at' => now(),
                    ];

                    $tally[$state->value]++;

                    if ($reason === SmsFailureReason::InvalidRecipient) {
                        $tally['not_mobile']++;
                    }
                }

                $tally['written'] += CampaignRecipient::insertOrIgnore(array_values($rows));
            });

        return $tally;
    }

    /**
     * @return array{0: CampaignRecipientState, 1: SmsFailureReason|null}
     */
    private function readAttempt(SmsDeliveryAttempt $attempt, string $phone): array
    {
        if ($attempt->succeeded) {
            return [CampaignRecipientState::Accepted, null];
        }

        if (GhanaNetwork::forPhone($phone) === null) {
            return [CampaignRecipientState::Refused, SmsFailureReason::InvalidRecipient];
        }

        $error = (string) $attempt->error_message;
        $body = json_decode($error, true);

        // The raw body of a refused batch: a status number and no batch id.
        if (is_array($body) && array_key_exists('batchId', $body) && $body['batchId'] === null) {
            $described = SmsFailureReason::describeHubtelStatus($body['status'] ?? null);

            return [CampaignRecipientState::Refused, SmsFailureReason::classify($described ?? $error)];
        }

        $reason = SmsFailureReason::classify($error);

        // Named reasons other than a dropped connection are Hubtel answering.
        // A connection error or an unread reply may still have been delivered.
        return in_array($reason, [SmsFailureReason::Connection, SmsFailureReason::Unknown], true)
            ? [CampaignRecipientState::Unsure, $reason]
            : [CampaignRecipientState::Refused, $reason];
    }

    /** 233XXXXXXXXX to +233XXXXXXXXX. */
    private function stored(string $phone): string
    {
        return '+'.ltrim($phone, '+');
    }

    /** +233XXXXXXXXX to 233XXXXXXXXX. */
    private function hubtel(string $phone): string
    {
        return ltrim($phone, '+');
    }
}
