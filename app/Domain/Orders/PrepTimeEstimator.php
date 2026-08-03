<?php

namespace App\Domain\Orders;

use Illuminate\Support\Facades\DB;

/**
 * How long to tell the customer their food will take.
 *
 * `orders.estimated_prep_time` existed as a nullable column that nothing ever
 * wrote, so every confirmation SMS went out reading "Estimated time:  mins."
 * with a hole where the number should be. This fills it from the one honest
 * source available: how long this branch's own kitchen has actually taken
 * recently, measured from the `preparing` → `ready` transitions already
 * recorded in `order_status_history`.
 *
 * Two deliberate limits on that measurement:
 *
 *  - **The answer is capped.** Whatever the kitchen has really been doing, the
 *    customer is never quoted longer than `orders.prep_time.max_minutes`. The
 *    quote is a service promise the business is making, not a report on past
 *    performance — a branch having a bad week should not start telling people
 *    to expect 40 minutes. The uncapped figure stays visible in analytics,
 *    which is where a slow kitchen should be noticed.
 *
 *  - **A thin sample is not trusted.** Under `min_sample` measured orders the
 *    branch is quoted the default instead of its own average. Lakeside and East
 *    Legon opened with no history at all, and one unusually fast first order
 *    should not set the promise for every order after it.
 *
 * The median is used rather than the mean, because one order that sat in
 * `preparing` over a lunch rush drags a mean up and leaves a median alone.
 */
class PrepTimeEstimator
{
    /** Statuses that mean the kitchen has finished. */
    private const DONE_STATUSES = ['ready', 'ready_for_pickup'];

    /**
     * Minutes to quote for an order at this branch. Always a usable number —
     * there is no null path, because a missing estimate is what produced the
     * broken message in the first place.
     */
    public function forBranch(?int $branchId): int
    {
        $measured = $branchId !== null ? $this->recentMedianMinutes($branchId) : null;

        $minutes = $measured ?? (float) config('orders.prep_time.default_minutes', 15);

        return (int) round(max(
            (float) config('orders.prep_time.min_minutes', 5),
            min((float) config('orders.prep_time.max_minutes', 15), $minutes),
        ));
    }

    /**
     * Median observed `preparing` → `ready` time at this branch, or null when
     * there is not enough history to be worth reading.
     */
    private function recentMedianMinutes(int $branchId): ?float
    {
        // Bounded in two directions before any history is touched: the newest N
        // orders, and only those inside the lookback window. This runs on every
        // order creation, and a busy branch a year from now would otherwise be
        // dragging a month of status rows into PHP each time somebody orders.
        $orderIds = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', now()->subDays((int) config('orders.prep_time.lookback_days', 30)))
            ->orderByDesc('id')
            ->limit((int) config('orders.prep_time.sample_size', 200))
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return null;
        }

        $rows = DB::table('order_status_history as h')
            ->whereIn('h.order_id', $orderIds)
            ->whereIn('h.status', array_merge(['preparing'], self::DONE_STATUSES))
            ->whereNull('h.deleted_at')
            ->select('h.order_id', 'h.status', 'h.changed_at')
            ->get();

        // First timestamp per order on each side. An order can be pushed back to
        // `preparing` after being marked ready; the first of each is the honest
        // pair, and taking the last would measure the correction, not the cook.
        $started = [];
        $finished = [];

        foreach ($rows as $row) {
            $at = strtotime((string) $row->changed_at);
            $id = (int) $row->order_id;

            if ($row->status === 'preparing') {
                $started[$id] = isset($started[$id]) ? min($started[$id], $at) : $at;

                continue;
            }

            $finished[$id] = isset($finished[$id]) ? min($finished[$id], $at) : $at;
        }

        $saneMax = (int) config('orders.prep_time.sane_max_minutes', 180);
        $durations = [];

        foreach ($started as $id => $startedAt) {
            if (! isset($finished[$id])) {
                continue;
            }

            $minutes = ($finished[$id] - $startedAt) / 60;

            if ($minutes >= 0 && $minutes <= $saneMax) {
                $durations[] = $minutes;
            }
        }

        if (count($durations) < (int) config('orders.prep_time.min_sample', 5)) {
            return null;
        }

        sort($durations);
        $middle = intdiv(count($durations), 2);

        return count($durations) % 2 === 1
            ? $durations[$middle]
            : ($durations[$middle - 1] + $durations[$middle]) / 2;
    }
}
