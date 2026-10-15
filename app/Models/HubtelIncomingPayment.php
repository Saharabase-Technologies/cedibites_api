<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment a customer made to a branch by dialling its code, which Hubtel's
 * status check has called Paid. Written only by BranchCodePayments::verify.
 */
class HubtelIncomingPayment extends Model
{
    protected $fillable = [
        'branch_id',
        'account_number',
        'client_reference',
        'network_transaction_id',
        'hubtel_transaction_id',
        'payer_number',
        'payer_name',
        'amount',
        'amount_charged',
        'paid_at',
        'verified_at',
        'notification_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_charged' => 'decimal:2',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The payer's number as people write it: 0247879103.
     *
     * The till shows it whole with the MoMo name (D13), so the cashier can
     * match the customer standing there to the payment.
     */
    public function payerLocalNumber(): ?string
    {
        return self::localNumber($this->payer_number);
    }

    /** 233247879103 to 0247879103. Anything else comes back as it was. */
    public static function localNumber(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        return preg_match('/^233(\d{9})$/', $number, $m) ? '0'.$m[1] : $number;
    }
}
