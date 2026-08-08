<?php

namespace App\Services\Automation;

use App\Enums\AutomationEvent;
use App\Models\AutomationRule;

/**
 * Whether the thing a rule waits for actually happened.
 *
 * One class rather than a copy in the evaluator and another in the dry run. Two
 * implementations would drift, and the day they did, the dry run would promise
 * one thing and the live rule would do another — which is worse than having no
 * dry run at all, because somebody would have approved the send on the strength
 * of it.
 */
class EventMatcher
{
    public function matches(AutomationRule $rule, OrderMilestones $m): bool
    {
        return match ($rule->event) {
            AutomationEvent::FirstOrder => $m->isFirstOrder(),
            AutomationEvent::FirstAtBranch => $m->isFirstAtBranch(),
            AutomationEvent::FirstOrderType => $m->isFirstOrderType(),
            AutomationEvent::TriedSomethingNew => $m->triedSomethingNew(),

            AutomationEvent::NthOrder => $rule->config('order_number') !== null
                && $m->orderNumber() === (int) $rule->config('order_number'),

            // Null days-since means this is their first order, which is not a
            // return. Firing a win-back at somebody who has never left is the
            // most obviously wrong message this engine could send.
            AutomationEvent::ReturnedAfterGap => $rule->config('gap_days') !== null
                && $m->daysSincePrevious() !== null
                && $m->daysSincePrevious() >= (int) $rule->config('gap_days'),

            AutomationEvent::HighValueOrder => $rule->config('minimum_amount') !== null
                && $m->amount() >= (float) $rule->config('minimum_amount'),
        };
    }
}
