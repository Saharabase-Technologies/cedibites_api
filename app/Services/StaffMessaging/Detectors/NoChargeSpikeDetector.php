<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Models\Order;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\Detectors\Concerns\ResolvesOrderActor;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Orders going through as `no_charge` above a threshold.
 *
 * This is the payment method with no money attached to it. Staff meals, remakes
 * and goodwill gestures are all legitimate uses and the rule assumes they are —
 * but it is also the obvious route for food to leave the building unpaid, and it
 * is the one payment method where the till balancing perfectly proves nothing.
 *
 * Reports the value as well as the count. Six no-charge orders worth twelve cedis
 * between them is lunch; six worth four hundred is a different conversation, and
 * the count alone cannot tell them apart.
 */
class NoChargeSpikeDetector implements DetectsStaffEvent
{
    use ResolvesOrderActor;

    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        $threshold = (int) $rule->condition('threshold');
        $windowHours = (int) $rule->condition('window_hours');

        $windowStart = now()->subHours($windowHours);
        $from = $windowStart->greaterThan($since) ? $windowStart : $since;

        return Order::query()
            ->where('created_at', '>=', $from)
            ->whereHas('payments', fn ($q) => $q->where('payment_method', 'no_charge'))
            ->with(['assignedEmployee', 'statusHistory'])
            ->get()
            ->groupBy(fn (Order $order) => $this->orderActorUserId($order) ?? 0)
            // Group 0 is "no identifiable staff member". Dropped rather than
            // reported: it is a real bucket that can grow large, and it has
            // nobody to send to, so keeping it would produce a permanent
            // no_recipients fire on every single run.
            ->reject(fn (Collection $orders, $userId) => (int) $userId === 0)
            ->filter(fn (Collection $orders) => $orders->count() >= $threshold)
            ->map(function (Collection $orders, $userId) use ($windowHours) {
                return new RuleMatch(
                    subject: null,
                    actorUserId: (int) $userId,
                    branchId: $orders->first()->branch_id,
                    mergeData: [
                        'count' => $orders->count(),
                        'window_hours' => $windowHours,
                        'amount' => number_format((float) $orders->sum('total_amount'), 2),
                    ],
                );
            })
            ->values();
    }

    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        return true;
    }
}
