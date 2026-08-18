<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Helpers\PhoneHelper;
use App\Models\Order;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\Detectors\Concerns\ResolvesOrderActor;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One phone number appearing on an implausible number of orders in a short window
 * at a single branch.
 *
 * This is the fake number that IS well-formed and does NOT look invented —
 * somebody's own number, or one memorised once and reused all day. Nothing about
 * the number itself gives it away; only the repetition does.
 *
 * Scoped to one branch and one window because a genuinely loyal customer buying
 * lunch every day is a different shape: spread across days, and often across
 * branches. Counting company-wide over a month would flag exactly the customers
 * worth keeping.
 *
 * Matching is on the NORMALISED number. `0244123456` and `+233244123456` are the
 * same phone, and counting the written forms separately means the reuse hides
 * behind whichever form was typed.
 */
class RepeatedPhoneDetector implements DetectsStaffEvent
{
    use ResolvesOrderActor;

    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        $threshold = (int) $rule->condition('threshold');
        $windowHours = (int) $rule->condition('window_hours');
        $windowStart = now()->subHours($windowHours);

        // The window governs, but never reach further back than the caller asked
        // for — the dry run passes a bounded `since` and must not be able to
        // trigger a full-table scan through this.
        $from = $windowStart->greaterThan($since) ? $windowStart : $since;

        $orders = Order::query()
            ->where('created_at', '>=', $from)
            ->whereIn('order_source', $this->staffEnteredSources())
            ->whereNotNull('contact_phone')
            ->with(['assignedEmployee', 'statusHistory'])
            ->get();

        return $orders
            ->groupBy(fn (Order $order) => $order->branch_id.'|'.PhoneHelper::normalize($order->contact_phone))
            ->filter(fn (Collection $group) => $group->count() >= $threshold)
            ->map(function (Collection $group) use ($windowHours) {
                // The most recent order carries the match. Reporting the whole
                // group would message the same person once per order, which is
                // the pile-on the cooldown exists to prevent — better not to
                // create it in the first place.
                $latest = $group->sortByDesc('created_at')->first();

                return new RuleMatch(
                    subject: $latest,
                    actorUserId: $this->orderActorUserId($latest),
                    branchId: $latest->branch_id,
                    mergeData: [
                        'order_number' => $latest->order_number,
                        'customer_phone' => $latest->contact_phone,
                        'count' => $group->count(),
                        'window_hours' => $windowHours,
                    ],
                );
            })
            ->values();
    }

    /**
     * The count can only have grown since detection — orders are not unmade — so
     * a match that held then still holds now.
     */
    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        return $match->subject instanceof Order;
    }
}
