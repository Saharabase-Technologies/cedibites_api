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

    /** The last four digits, which is all the till needs to match a customer. */
    public function payerLastFour(): ?string
    {
        return $this->payer_number ? substr($this->payer_number, -4) : null;
    }
}
