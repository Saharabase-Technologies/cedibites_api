<?php

namespace App\Enums\Inventory;

/**
 * Reconciliation cycle lifecycle (architecture §6.5).
 *
 *   open → closed
 *
 * "The inventory management is basically like accounting — whatever comes in,
 * whatever comes out must cancel out." A cycle opens with a system-quantity
 * snapshot, the warehouse manager counts everything physically, and posting the
 * adjustments cancels the variance out (cycle_adjustment movements bring the
 * ledger to the counted actual) and closes the cycle — "the system is reset to
 * zero, another cycle begins." Manager-initiated, not calendar-enforced.
 */
enum ReconciliationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
