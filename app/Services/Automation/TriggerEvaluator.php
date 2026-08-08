<?php

namespace App\Services\Automation;

use App\Jobs\SendAutomationMessage;
use App\Models\AutomationFire;
use App\Models\AutomationRule;
use App\Models\Order;
use App\Services\Campaigns\AudienceResolver;

/**
 * Which rule, if any, this order sets off.
 *
 * Decides and records; the actual send is queued for later and re-checks
 * everything decided here — see SendAutomationMessage for why. With the kill
 * switch down nothing wins, so the engine still evaluates real traffic and fills
 * the log while no customer hears a thing. That is how a rule earns trust before
 * anybody turns it on.
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
        $winningRule = null;

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
                $winningRule = $rule;
            }
        }

        if ($winner !== null) {
            /*
             * Queued for later rather than sent now, and the delay is the point:
             * asking how the food was while somebody is still eating it is worse
             * than not asking at all.
             *
             * The job re-checks every guard when it runs. Between here and there
             * the order can be cancelled, the rule switched off, or the same
             * person messaged by something else entirely.
             */
            SendAutomationMessage::dispatch($winner->id)
                ->delay(now()->addMinutes(max(0, (int) $winningRule->delay_minutes)));
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
