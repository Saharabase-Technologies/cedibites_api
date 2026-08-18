<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Models\Order;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\Detectors\Concerns\ResolvesOrderActor;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * An order that has sat in one status longer than it should.
 *
 * The headline rule: an order marked received and then left there for close to
 * three hours, which is what prompted this feature.
 *
 * Parameterised by status, so "received, not moved in 15 minutes", "ready, not
 * collected in 20" and "out for delivery over an hour" are three rules of one
 * type rather than three code paths that will drift apart.
 *
 * How long it has sat is measured from the status history entry that put it
 * there, NOT from `updated_at`. Any edit at all touches `updated_at` — adding a
 * note, correcting a phone number — so measuring from it lets someone reset a
 * three-hour stall by opening the order and changing nothing.
 */
class OrderStalledDetector implements DetectsStaffEvent
{
    use ResolvesOrderActor;

    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        $status = (string) $rule->condition('status');
        $minutes = (int) $rule->condition('minutes');
        $cutoff = now()->subMinutes($minutes);

        return Order::query()
            ->where('status', $status)
            ->where('created_at', '>=', $since)
            // assignedEmployee is load-bearing now that the actor is the
            // order-taker rather than the last mover. Lazy loading is prevented
            // in this app, and the evaluator catches per-rule exceptions, so
            // omitting it makes the rule silently produce nothing.
            ->with(['assignedEmployee', 'statusHistory' => fn ($q) => $q->orderByDesc('changed_at')])
            ->get()
            ->map(function (Order $order) use ($status, $cutoff, $rule) {
                $enteredAt = $this->enteredStatusAt($order, $status);

                if ($enteredAt === null || $enteredAt->greaterThan($cutoff)) {
                    return null;
                }

                return new RuleMatch(
                    subject: $order,
                    actorUserId: $this->actorFor($order, $status, $rule),
                    branchId: $order->branch_id,
                    mergeData: [
                        'order_number' => $order->order_number,
                        'status' => str_replace('_', ' ', $status),
                        'minutes' => (int) $enteredAt->diffInMinutes(now()),
                        'customer_phone' => $order->contact_phone,
                    ],
                );
            })
            ->filter()
            ->values();
    }

    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        $order = $match->subject;

        if (! $order instanceof Order) {
            return false;
        }

        // The only question that matters: has it moved? A cancelled order counts
        // as moved — nobody needs chasing about an order that no longer exists.
        return $order->fresh()?->status === (string) $rule->condition('status');
    }

    /**
     * When the order entered this status.
     *
     * Falls back to `created_at` because the very first status is frequently set
     * as part of creating the order rather than as a recorded transition — and
     * `received` is precisely the status this rule is most often pointed at, so
     * the fallback is the normal path, not an edge case.
     */
    private function enteredStatusAt(Order $order, string $status): ?CarbonInterface
    {
        $entry = $order->statusHistory->firstWhere('status', $status);

        return $entry?->changed_at ?? $order->created_at;
    }

    /**
     * Who is answerable: the person who took the order.
     *
     * NOT whoever last moved it. Decided with the user 2026-08-18 — "the one who
     * placed it or received it, that is who we hold responsible." On a stalled
     * `ready` order the last mover is whoever marked it ready, usually the
     * kitchen; the person accountable for it reaching the customer is the one
     * who took it.
     *
     * Deliberately the same `orderActorUserId()` the phone rules use, so "actor"
     * means one thing across every rule rather than quietly meaning something
     * different depending on which event fired.
     *
     * `'actor' => 'mover'` in the conditions opts back into last-mover for a
     * rule where the person who put it in this status really is the responsible
     * one.
     *
     * Null for a website order nobody has touched — there is genuinely no
     * individual to caution, and blaming the assignee would put a caution on
     * somebody who may never have seen it.
     */
    private function actorFor(Order $order, string $status, StaffMessageRule $rule): ?int
    {
        if ($rule->condition('actor') === 'mover') {
            $entry = $order->statusHistory->firstWhere('status', $status);

            if ($entry?->changed_by_id) {
                return (int) $entry->changed_by_id;
            }
        }

        return $this->orderActorUserId($order);
    }
}
