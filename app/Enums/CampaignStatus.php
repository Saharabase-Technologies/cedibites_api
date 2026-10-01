<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Where a campaign is in its life.
 *
 * The only transition that spends money is into Sending, and what Hubtel has
 * accepted cannot be recalled. Everything before the first send is free to
 * change, everything after it is history.
 */
enum CampaignStatus: string
{
    use HasEnumHelpers;

    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';

    /**
     * Stopped part way, with people still waiting.
     *
     * Hubtel refused a chunk for a reason the next chunk would meet too (no
     * credit, bad credentials, an outage), so the rest was held back unsent
     * instead of being thrown at the same wall. Resumable.
     */
    case Paused = 'paused';

    /** Every recipient was accepted by Hubtel. */
    case Sent = 'sent';

    /**
     * Finished, and some of the list was not accepted.
     *
     * Its own status because "Sent" on a campaign that reached 39 of 3,539 is a
     * statement nobody would make out loud. Resumable for the part that was
     * refused.
     */
    case PartlySent = 'partly_sent';

    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Sending => 'Sending',
            self::Paused => 'Paused',
            self::Sent => 'Sent',
            self::PartlySent => 'Partly sent',
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
        return in_array($this, [self::Sending, self::Paused, self::Sent, self::PartlySent, self::Failed], true);
    }

    /** Whether there can be people left to send to. */
    public function isResumable(): bool
    {
        return in_array($this, [self::Paused, self::PartlySent, self::Failed], true);
    }
}
