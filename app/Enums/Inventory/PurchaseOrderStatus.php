<?php

namespace App\Enums\Inventory;

/**
 * Purchase Order lifecycle.
 *
 *   draft → pending_approval → sent → partially_received → received → closed
 *         ↘ sent (when under approval threshold)
 *   (draft | pending_approval | sent) → cancelled  (with reason)
 *
 * Approval threshold rule lives in the controller/engine; this enum owns the
 * allowed transitions only.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Statuses a clerk can record receipts against.
     */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Sent], true);
    }

    public function isClosable(): bool
    {
        return in_array($this, [self::PartiallyReceived, self::Received], true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether a direct transition to $next is permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Sent, self::Cancelled],
            self::PendingApproval => [self::Sent, self::Cancelled],
            self::Sent => [self::PartiallyReceived, self::Received, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Closed],
            self::Received => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }
}
