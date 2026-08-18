<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Helpers\PhoneQuality;
use App\Models\Order;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\Detectors\Concerns\ResolvesOrderActor;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A customer phone number that was typed to satisfy the field rather than to
 * reach anybody.
 *
 * Two classes of number reach here, and only the second one should:
 *
 *  - Malformed. Once the validation rule is in place these stop being creatable
 *    at all, so this detector will find only historical ones. That is worth
 *    keeping rather than deleting — the rule can be dry-run over the months
 *    before the fix and show what the problem actually looked like.
 *  - Well-formed but invented — `0244444444`, `0241234567`. These stay
 *    creatable on purpose. They are indistinguishable from a real number by
 *    format alone, and refusing them at the till would eventually block a genuine
 *    customer mid-queue with no way round it. So the till accepts them and this
 *    rule follows up afterwards, which is the right order.
 */
class SuspiciousPhoneDetector implements DetectsStaffEvent
{
    use ResolvesOrderActor;

    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        return Order::query()
            ->where('created_at', '>=', $since)
            ->whereIn('order_source', $this->staffEnteredSources())
            ->with(['assignedEmployee', 'statusHistory'])
            ->get()
            ->map(function (Order $order) {
                $phone = $order->contact_phone;
                $reason = $this->reasonFor($phone);

                if ($reason === null) {
                    return null;
                }

                return new RuleMatch(
                    subject: $order,
                    actorUserId: $this->orderActorUserId($order),
                    branchId: $order->branch_id,
                    mergeData: [
                        'order_number' => $order->order_number,
                        'customer_phone' => $phone,
                        'reason' => $reason,
                    ],
                );
            })
            ->filter()
            ->values();
    }

    /**
     * The phone on a saved order never changes on its own, so a match found at
     * detection is still true at send time. Returns true rather than re-querying
     * for a value that cannot have moved.
     */
    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        return $match->subject instanceof Order;
    }

    private function reasonFor(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return 'no number at all';
        }

        if (! PhoneQuality::isWellFormed($phone)) {
            return 'not a usable Ghana number';
        }

        return PhoneQuality::suspicionReason($phone);
    }
}
