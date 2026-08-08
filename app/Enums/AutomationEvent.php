<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * The moment a rule reacts to.
 *
 * Every one of these is evaluated when an order reaches completed or delivered,
 * not when it is placed. That is the same seam the existing post-order feedback
 * uses, and it is the right one: an order that was taken and then cancelled is
 * not a milestone, and asking somebody how their food was before they have eaten
 * it is worse than not asking.
 *
 * Each case names something that happened to THIS order relative to the
 * customer's history. That is what makes them milestones rather than filters —
 * "first order at this branch" is only true once, and the whole point of a
 * trigger is that it fires on the transition rather than on the state.
 */
enum AutomationEvent: string
{
    use HasEnumHelpers;

    /** Their very first order with us, ever. */
    case FirstOrder = 'first_order';

    /** First time at this branch, even if they have ordered elsewhere. */
    case FirstAtBranch = 'first_at_branch';

    /** First time using this fulfilment type — their first delivery, or first pickup. */
    case FirstOrderType = 'first_order_type';

    /**
     * Bought a menu option they have never bought before.
     *
     * Option-level, not dish-level, and that distinction is the point:
     * "Jollof Regular → Jollof Large" is a different signal from
     * "Jollof → Waakye", and only the option can tell them apart.
     */
    case TriedSomethingNew = 'tried_something_new';

    /** Their Nth order — 3rd, 10th, 25th. The number is in the rule's config. */
    case NthOrder = 'nth_order';

    /** First order after a quiet spell. A win-back that worked. */
    case ReturnedAfterGap = 'returned_after_gap';

    /** An order above a value threshold. */
    case HighValueOrder = 'high_value_order';

    public function label(): string
    {
        return match ($this) {
            self::FirstOrder => 'First ever order',
            self::FirstAtBranch => 'First order at a branch',
            self::FirstOrderType => 'First delivery or first pickup',
            self::TriedSomethingNew => 'Tried something new',
            self::NthOrder => 'Reached a certain number of orders',
            self::ReturnedAfterGap => 'Came back after a gap',
            self::HighValueOrder => 'Spent over a certain amount',
        };
    }

    /** Said the way an operator would say it. */
    public function description(): string
    {
        return match ($this) {
            self::FirstOrder => 'The very first time somebody orders from CediBites.',
            self::FirstAtBranch => 'First time at this branch, even if they have ordered from us elsewhere.',
            self::FirstOrderType => 'First time they have had it delivered, or first time they collected.',
            self::TriedSomethingNew => 'Ordered something from the menu they have never had before.',
            self::NthOrder => 'Their 3rd, 10th, 25th order — whichever you choose.',
            self::ReturnedAfterGap => 'Ordered again after going quiet for a while.',
            self::HighValueOrder => 'An order worth more than an amount you set.',
        };
    }

    /**
     * The extra settings this event needs, beyond the audience conditions.
     *
     * @return array<int, string>
     */
    public function configKeys(): array
    {
        return match ($this) {
            self::NthOrder => ['order_number'],
            self::ReturnedAfterGap => ['gap_days'],
            self::HighValueOrder => ['minimum_amount'],
            default => [],
        };
    }
}
