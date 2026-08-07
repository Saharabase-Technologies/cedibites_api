<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Who a campaign goes to.
 *
 * These are the six the contact export already resolves. They are not
 * reinvented here — AudienceResolver owns the logic and both the export and the
 * send call it, so the CSV and the blast cannot start disagreeing about who is
 * in "churned".
 *
 * Recency is measured from the last order that was not cancelled.
 */
enum CampaignSegment: string
{
    use HasEnumHelpers;

    case All = 'all';
    case Active = 'active';
    case AtRisk = 'at_risk';
    case Churned = 'churned';
    case Loyal = 'loyal';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everyone',
            self::Active => 'Active',
            self::AtRisk => 'Going quiet',
            self::Churned => 'Lapsed',
            self::Loyal => 'Regulars',
            self::OneTime => 'Ordered once',
        };
    }

    /** Said the way an operator would say it, for the picker. */
    public function description(): string
    {
        return match ($this) {
            self::All => 'Every number we hold — registered customers and guests who have ordered.',
            self::Active => 'Ordered in the last 30 days.',
            self::AtRisk => 'Last ordered 31 to 60 days ago.',
            self::Churned => 'Nothing for over 60 days.',
            self::Loyal => 'Two orders or more.',
            self::OneTime => 'Ordered exactly once.',
        };
    }
}
