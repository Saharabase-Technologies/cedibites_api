<?php

namespace App\Enums\Inventory;

/**
 * Stock requisition lifecycle (architecture §6.1).
 *
 *   draft → submitted → approved → fulfilled
 *                     ↘ rejected  ↘ fulfilled_short
 *
 * A branch requests stock from the warehouse. On approval the warehouse manager
 * sets the granted quantities and a fulfilling transfer is spawned; the
 * requisition flips to `fulfilled` automatically once that transfer is received.
 *
 * `fulfilled_short` is the delivery that arrived but was not all kept - the
 * branch refused spoiled or wrong goods on the doorstep. It exists because the
 * alternative was worse in both directions: calling it `fulfilled` overstates
 * what actually reached the branch in every report that counts fulfilment, and
 * leaving it `approved` (what the code used to do) strands it forever, reading
 * as "still waiting for delivery" long after the lorry has been and gone.
 *
 * A refusal does NOT automatically oblige the warehouse to send a replacement.
 * The goods went back, the loss is carried on the wastage claim, and if the
 * branch still needs them it asks again. That is a deliberate choice: the need
 * may have passed, or been covered elsewhere, by the time anyone looks.
 *
 * A short RECEIPT is a different thing entirely and still opens a dispute - see
 * TransferService::receive(). Stock neither end can account for is a
 * disagreement to settle, not a delivery to close.
 */
enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Fulfilled = 'fulfilled';
    case FulfilledShort = 'fulfilled_short';
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
        return in_array($this, [self::Fulfilled, self::FulfilledShort, self::Rejected], true);
    }

    /**
     * Did the branch actually receive anything? Both fulfilment states are
     * "the delivery happened"; use this rather than `=== Fulfilled` anywhere
     * asking whether a request was served, or the short ones vanish.
     */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Fulfilled, self::FulfilledShort], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Fulfilled => 'Fulfilled',
            self::FulfilledShort => 'Fulfilled short',
            self::Rejected => 'Rejected',
        };
    }
}
