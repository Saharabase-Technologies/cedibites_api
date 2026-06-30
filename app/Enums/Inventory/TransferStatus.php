<?php

namespace App\Enums\Inventory;

/**
 * Stock transfer lifecycle (architecture §6.2).
 *
 *   draft → submitted → approved → sent → received → closed
 *                                       ↘ disputed → closed_disputed
 *   (draft | submitted | approved) → cancelled
 *
 * Stock leaves the source at `sent` (transfer_out, FEFO) and arrives at the
 * destination at `received` (transfer_in). A short receipt routes to `disputed`;
 * the original is immutable and reconciled by a corrective transfer.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Sent = 'sent';
    case Received = 'received';
    case Disputed = 'disputed';
    case Closed = 'closed';
    case ClosedDisputed = 'closed_disputed';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved], true);
    }

    public function canSend(): bool
    {
        return $this === self::Approved;
    }

    public function canReceive(): bool
    {
        return $this === self::Sent;
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::Approved, self::Cancelled],
            self::Approved => [self::Sent, self::Cancelled],
            self::Sent => [self::Received, self::Disputed],
            self::Received => [self::Closed],
            self::Disputed => [self::ClosedDisputed],
            self::Closed, self::ClosedDisputed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
