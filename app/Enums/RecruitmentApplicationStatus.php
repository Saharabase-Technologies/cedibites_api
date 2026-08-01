<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Where an application sits.
 *
 * A submitted application is inert — no user, no employee, no login. It becomes
 * an account only on approval, which is the whole point of the review step.
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
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Whether this application can still be acted on. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
