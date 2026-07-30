<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * How many branches a role is assigned.
 *
 * A branch is not a decoration on a staff account — it is the scope every
 * branch-aware query reads (see User::accessibleLocationIds and
 * EmployeeController::index). Asking for one where the job has no branch
 * produced admins pinned to Ashaiman; not asking where the job has exactly one
 * produced managers running three branches at once. So the answer belongs to
 * the role, stated once, and every form and request reads it from here.
 */
enum BranchRule: string
{
    use HasEnumHelpers;

    /**
     * The job is company-wide. Any branch_ids sent are ignored rather than
     * refused — a client asking to pin an admin to a branch is not making an
     * error the admin can correct, it is asking for something that does not
     * exist.
     */
    case None = 'none';

    /** Exactly one. A branch manager runs a branch, singular. */
    case ExactlyOne = 'exactly_one';

    /** One or more. A rider covers the branches he is given. */
    case OneOrMore = 'one_or_more';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No branch — company-wide',
            self::ExactlyOne => 'Exactly one branch',
            self::OneOrMore => 'One or more branches',
        };
    }

    /** Whether the role takes a branch assignment at all. */
    public function requiresBranch(): bool
    {
        return $this !== self::None;
    }

    /** Whether the role may hold more than one branch. */
    public function allowsMany(): bool
    {
        return $this === self::OneOrMore;
    }
}
