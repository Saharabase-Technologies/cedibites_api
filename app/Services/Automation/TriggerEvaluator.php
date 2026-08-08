<?php

namespace App\Services\Automation;

use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Order;
use App\Services\Campaigns\AudienceResolver;

/**
 * Which rule, if any, this order sets off.
 *
 * Records what it decides and sends nothing. Sending is a separate step, and
 * keeping them apart is what lets the whole engine run against real traffic with
 * the kill switch down: rules match real orders, the log fills up, the reporting
 * works, and no customer hears a thing.
 *
 * ONE RULE WINS PER ORDER. A first delivery, of a new dish, at a new branch is
 * three matches on one order, and three texts in one afternoon is how an
 * automation feature gets switched off for good. The rest are written down as
 * suppressed so the log shows what was considered, not only what happened.
 */
class TriggerEvaluator
{
    public function __construct(
        private readonly AutomationGuard $guard,
        private readonly AudienceResolver $audience,
        private readonly EventMatcher $events,
    ) {}

    /**
     * Evaluate every live rule against one order.
     *
     * @return AutomationFire|null the firing that will send, if any
     */
    public function evaluate(Order $order): ?AutomationFire
    {
        if (! in_array($order->status, ['completed', 'delivered'], true)) {
            return null;
        }

        $milestones = new OrderMilestones($order);
        $phone = $milestones->phone();

        if ($phone === null) {
            return null;
        }

        $rules = AutomationRule::live()->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $profile = null;
        $winner = null;

        foreach ($rules as $rule) {
            if (! $this->events->matches($rule, $milestones)) {
                continue;
            }

            // Built once, and only when a rule's event has already matched —
            // the audience conditions are the expensive half and most orders
            // match no event at all.
            $profile ??= $this->audience->profileFromOrders(
                $this->historyIncluding($order, $milestones),
                $order->contact_name,
            );

            if (! $this->audience->profileMatches($profile, $phone, $rule->rules())) {
                continue;
            }

            // Already spoken for by a higher-priority rule. Recorded rather than
            // dropped: "this rule matched but something else got there first" is
            // a different fact from "this rule did not match", and only one of
            // them means the rule needs looking at.
            $reason = $winner !== null
                ? AutomationFire::LOWER_PRIORITY
                : $this->guard->objection($rule, $phone);

            $fire = AutomationFire::create([
                'automation_rule_id' => $rule->id,
                'order_id' => $order->id,
                'phone' => $phone,
                'fired_at' => now(),
                'suppressed_reason' => $reason,
            ]);

            if ($reason === null) {
                $winner = $fire;
            }
        }

        return $winner;
    }

    /**
     * This order plus everything before it, newest first.
     *
     * The current order counts. At somebody's third order, a condition of "three
     * orders or more" should be true — the milestone and the condition are
     * describing the same moment, and excluding the order that triggered the
     * rule would make them disagree by one, forever.
     *
     * @return array<int, Order>
     */
    private function historyIncluding(Order $order, OrderMilestones $milestones): array
    {
        $order->loadMissing('items:id,order_id,menu_item_id,menu_item_option_id');

        return [$order, ...$milestones->previousOrders()];
    }
}
