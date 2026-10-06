<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post from Hubtel's payment notifications, as it arrived.
 *
 * Kept whole, because Hubtel does not document it. VerifyHubtelPaymentNotification
 * asks Hubtel about each one and writes the answer to `outcome`; only a payment
 * Hubtel calls Paid becomes a HubtelIncomingPayment the till can see.
 */
class HubtelPaymentNotification extends Model
{
    protected $fillable = [
        'account_number',
        'branch_id',
        'method',
        'ip',
        'payload',
        'raw_body',
        'headers',
        'outcome',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
