<?php

namespace App\Enums\Inventory;

/**
 * Daily closing count lifecycle (architecture §6.4).
 *
 *   open → completed
 *
 * A location opens a count for a business date (expected quantities snapshotted
 * from the ledger), enters the physically counted quantities, and completes it -
 * locking in the variance. Missed days are simply dates with no closing at all,
 * surfaced on the calendar/variance report.
 */
enum DailyClosingStatus: string
{
    case Open = 'open';
    case Completed = 'completed';

    public function isEditable(): bool
    {
        return $this === self::Open;
    }
}
