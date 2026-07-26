<?php

namespace App\Enums\Inventory;

/**
 * Stock transfer lifecycle (architecture §6.2).
 *
 *   draft → submitted → approved → sent → received → closed
 *                                       ↘ disputed → closed_disputed
 *                                       ↘ rejected
 *   (draft | submitted | approved) → cancelled
 *
 * Stock leaves the source at `sent` (transfer_out, FEFO) and arrives at the
 * destination at `received` (transfer_in). A short receipt routes to `disputed`;
 * the original is immutable and reconciled by a corrective transfer.
 *
 * `rejected` is the door being shut: the destination refuses the whole
 * consignment, and the stock goes straight back to the source rather than
 * entering the destination's books at all. That distinction decides who carries
 * the loss - refuse at the door and it stays the sender's; sign for it and it
 * becomes yours to declare as wastage.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Sent = 'sent';
    case Received = 'received';
    case Disputed = 'disputed';
    case Rejected = 'rejected';
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

    /** Refusing the consignment outright is only possible while it is in transit. */
    public function canReject(): bool
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
            self::Sent => [self::Received, self::Disputed, self::Rejected],
            self::Received => [self::Closed],
            self::Disputed => [self::ClosedDisputed],
            self::Rejected, self::Closed, self::ClosedDisputed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
