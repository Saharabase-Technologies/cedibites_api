<?php

namespace App\Enums\Inventory;

/**
 * Wastage lifecycle.
 *
 *   (under threshold)              → approved
 *   (over threshold, at a branch)  → pending_return → pending_approval → approved
 *                                                                      ↘ rejected
 *   (over threshold, at warehouse) → pending_approval → approved / rejected
 *
 * The founder's rule: the warehouse manager supplied the goods, so he answers
 * for a branch claiming they arrived bad — and goods claimed bad must physically
 * come back to the warehouse before he signs it off. `pending_return` is that
 * journey; the return transfer carries the stock and its receipt advances the
 * claim.
 *
 * Approval governs classification and who carries the loss. It never gates the
 * ledger: stock moves when the physical event happens, so a day can always close
 * neutral without waiting on anyone's signature.
 */
enum WastageStatus: string
{
    case PendingReturn = 'pending_return';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::PendingReturn, self::PendingApproval], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled], true);
    }

    /** Only a claim still awaiting a decision can be approved or rejected. */
    public function canDecide(): bool
    {
        return $this === self::PendingApproval;
    }

    /** The recorder may withdraw their own claim while nothing has moved yet. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::PendingReturn, self::PendingApproval], true);
    }

    /**
     * Evidence can only be added while the claim is live. Once it is settled the
     * photo set is the record of what the decision was made on, and letting
     * either side bolt pictures onto a closed argument would destroy that.
     */
    public function acceptsEvidence(): bool
    {
        return $this->isOpen();
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingReturn => 'Awaiting return',
            self::PendingApproval => 'Awaiting approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }
}
