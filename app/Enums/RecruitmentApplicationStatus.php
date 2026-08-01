<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Where a submitted set of details sits.
 *
 * A submission is inert — no user, no employee, no login — until someone checks
 * it and creates the account. `Rejected` is not a decision about the person:
 * these are staff who have already been taken on, so it is for a duplicate, a
 * mistyped number, or somebody who did not end up starting. The labels say
 * "discarded" for that reason.
 */
enum RecruitmentApplicationStatus: string
{
    use HasEnumHelpers;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Approved => 'Added',
            self::Rejected => 'Discarded',
        };
    }

    /** Whether this application can still be acted on. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
