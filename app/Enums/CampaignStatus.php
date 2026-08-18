<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Where a campaign is in its life.
 *
 * The only transition that spends money is Draft/Scheduled → Sending, and it is
 * one-way: once Hubtel has accepted a chunk there is nothing to undo. Everything
 * before it is free to change, everything after it is history.
 */
enum CampaignStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether the message and audience can still be changed. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    /** Whether this campaign has started spending. */
    public function hasStarted(): bool
    {
        return in_array($this, [self::Sending, self::Sent, self::Failed], true);
    }
}
