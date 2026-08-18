<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Models\OrderStatusHistory;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One person cancelling an unusual number of orders in a rolling window.
 *
 * Counted from `order_status_history`, not from `orders.cancelled_at`, because
 * the history is the only place that records WHO did it. The order itself knows
 * it was cancelled and when, and says nothing about by whose hand.
 *
 * Every individual cancellation may be perfectly proper — the kitchen ran out,
 * the customer changed their mind. The rule deliberately does not judge any one
 * of them; it reports a rate, and the message is worded as a question rather than
 * an accusation.
 */
class CancellationSpikeDetector implements DetectsStaffEvent
{
    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        $threshold = (int) $rule->condition('threshold');
        $windowHours = (int) $rule->condition('window_hours');

        $windowStart = now()->subHours($windowHours);
        $from = $windowStart->greaterThan($since) ? $windowStart : $since;

        return OrderStatusHistory::query()
            ->where('status', 'cancelled')
            ->where('changed_at', '>=', $from)
            ->whereNotNull('changed_by_id')
            ->with('order')
            ->get()
            ->groupBy('changed_by_id')
            ->filter(fn (Collection $entries) => $entries->count() >= $threshold)
            ->map(function (Collection $entries, $userId) use ($windowHours) {
                return new RuleMatch(
                    // No subject record: this is about a person over a window,
                    // not about any one order. RuleMatch::cooldownKey falls back
                    // to the actor, which is what should be rate-limited here.
                    subject: null,
                    actorUserId: (int) $userId,
                    branchId: $entries->first()->order?->branch_id,
                    mergeData: [
                        'count' => $entries->count(),
                        'window_hours' => $windowHours,
                    ],
                );
            })
            ->values();
    }

    /**
     * A cancellation cannot be un-cancelled, so the count only grows. What held
     * at detection holds at send.
     */
    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        return true;
    }
}
