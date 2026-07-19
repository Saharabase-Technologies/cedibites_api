<?php

namespace App\Enums\Inventory;

/**
 * Stock requisition lifecycle (architecture §6.1).
 *
 *   draft → submitted → approved → fulfilled
 *                     ↘ rejected
 *
 * A branch requests stock from the warehouse. On approval the warehouse manager
 * sets the granted quantities and a fulfilling transfer is spawned; the
 * requisition flips to `fulfilled` automatically once that transfer is received.
 */
enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case Rejected = 'rejected';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canDecide(): bool
    {
        return $this === self::Submitted;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Fulfilled, self::Rejected], true);
    }
}
