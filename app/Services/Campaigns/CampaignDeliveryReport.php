<?php

namespace App\Services\Campaigns;

use App\Enums\DeliveryOutcome;
use App\Models\Campaign;
use App\Models\CampaignDelivery;

/**
 * How much of a campaign actually arrived, and what happened to the rest.
 *
 * The headline question this answers is not "how many failed?" but "how many did
 * we reach, and of the ones we did not, which are worth trying again?". Those
 * are different groups and merging them is what makes a delivery report
 * something nobody acts on.
 */
class CampaignDeliveryReport
{
    /**
     * The breakdown, with pending resolved against the polling window.
     *
     * A row stays Pending only while we are still asking. Once the window has
     * closed we have stopped asking, so it becomes Unconfirmed — computed here
     * rather than written by a job, because the campaign's age is the only input
     * and a nightly sweep to set a value that can be derived is a moving part
     * that can fall behind and lie.
     *
     * @return array{
     *     accepted: int, delivered: int, failed: int, pending: int,
     *     unconfirmed: int, unknown: int, delivery_rate: float|null,
     *     is_final: bool, checked_at: string|null, window_hours: int
     * }
     */
    public function summarise(Campaign $campaign): array
    {
        $counts = CampaignDelivery::where('campaign_id', $campaign->id)
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome')
            ->all();

        $get = fn (DeliveryOutcome $o): int => (int) ($counts[$o->value] ?? 0);

        $windowHours = (int) config('campaigns.delivery_poll_hours', 48);
        $isFinal = $this->windowHasClosed($campaign, $windowHours);

        $pending = $get(DeliveryOutcome::Pending);

        return [
            // What Hubtel accepted. The denominator, and never recomputed from
            // these rows — see the campaigns table comment.
            'accepted' => (int) $campaign->sent_count,

            'delivered' => $get(DeliveryOutcome::Delivered),
            'failed' => $get(DeliveryOutcome::Failed),

            // Still moving while the window is open; once it closes, whatever
            // never resolved is unconfirmed rather than pending.
            'pending' => $isFinal ? 0 : $pending,
            'unconfirmed' => $get(DeliveryOutcome::Unconfirmed) + ($isFinal ? $pending : 0),

            /*
             * Accepted messages we hold no status row for at all.
             *
             * Not folded into any other bucket. It means the poller has not
             * reached them yet, or the campaign sent before this table existed —
             * both are gaps in our own record, not statements about the
             * recipient, and a report that quietly counted them as failures
             * would be describing our bookkeeping as a delivery problem.
             */
            'unknown' => max(0, (int) $campaign->sent_count - array_sum($counts)),

            'delivery_rate' => $campaign->sent_count > 0
                ? round($get(DeliveryOutcome::Delivered) / $campaign->sent_count * 100, 1)
                : null,

            'is_final' => $isFinal,
            'checked_at' => $campaign->delivery_checked_at?->toIso8601String(),
            'window_hours' => $windowHours,
        ];
    }

    /**
     * Whether we have stopped asking about this campaign.
     *
     * Mirrors the filter in CampaignDeliveryPoller::pollRecent() — if that stops
     * polling a campaign, this has to stop calling its rows pending, or the
     * report would show messages as "still trying" that nothing is trying.
     */
    public function windowHasClosed(Campaign $campaign, ?int $windowHours = null): bool
    {
        $windowHours ??= (int) config('campaigns.delivery_poll_hours', 48);

        return $campaign->started_at !== null
            && $campaign->started_at->lt(now()->subHours($windowHours));
    }

    /**
     * How delivery built up over time, as hours since the send.
     *
     * The tail is the interesting part. Most messages land within minutes; the
     * ones that arrive hours later are handsets that were switched off, which is
     * exactly the signal that says whether a poor delivery rate is a number
     * problem or an availability problem.
     *
     * Read from updated_at, which is when the poller first saw the row settle —
     * an approximation of the delivery moment, and the closest one available.
     * Hubtel's batch endpoint returns no delivery timestamp.
     *
     * @return array<int, array{hour: int, delivered: int}>
     */
    public function curve(Campaign $campaign, array $marks = [1, 6, 24, 48]): array
    {
        if ($campaign->started_at === null) {
            return [];
        }

        $out = [];

        foreach ($marks as $hour) {
            $out[] = [
                'hour' => $hour,
                'delivered' => CampaignDelivery::where('campaign_id', $campaign->id)
                    ->where('outcome', DeliveryOutcome::Delivered->value)
                    ->where('updated_at', '<=', $campaign->started_at->copy()->addHours($hour))
                    ->count(),
            ];
        }

        return $out;
    }
}
