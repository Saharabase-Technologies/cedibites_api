<?php

namespace App\Enums\Inventory;

/**
 * Where a wastage claim came from - and, critically, whether it is the thing
 * that moves the stock or merely the label on a loss the ledger has already
 * recorded.
 *
 * THE RULE: one movement per loss, ever. Double-deducting the same spoiled
 * chicken is the easiest way to make the whole ledger lie, so every origin
 * declares plainly whether it posts stock or only classifies.
 *
 *   Manual            - nothing has moved yet; approving posts the deduction.
 *   DeliveryRejection - refused at the door; the stock went back to the source,
 *                       and approving writes it off where it now sits.
 *   DailyClosing      - the count adjustment already brought the ledger to the
 *                       counted actual. This record only says why.
 *   Reconciliation    - same, via the cycle adjustment.
 *   TransferShortfall - the stock left the source at `send` and never arrived,
 *                       so the loss was recorded at the short receipt. This
 *                       record only attributes it.
 */
enum WastageOrigin: string
{
    case Manual = 'manual';
    case DeliveryRejection = 'delivery_rejection';
    case DailyClosing = 'daily_closing';
    case Reconciliation = 'reconciliation';
    case TransferShortfall = 'transfer_shortfall';

    /** Does approving this claim write a `wastage` movement? */
    public function postsStock(): bool
    {
        return match ($this) {
            self::Manual, self::DeliveryRejection => true,
            self::DailyClosing, self::Reconciliation, self::TransferShortfall => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Declared',
            self::DeliveryRejection => 'Delivery rejected',
            self::DailyClosing => 'Daily closing variance',
            self::Reconciliation => 'Stock-take variance',
            self::TransferShortfall => 'Transfer shortfall',
        };
    }
}
