<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a cashier typed the transaction ID from a customer's MoMo message
 * into the till, and what Hubtel said. Kept so the same message shown for a
 * second sale is caught.
 */
class HubtelPaymentCheck extends Model
{
    protected $fillable = [
        'branch_id',
        'employee_id',
        'transaction_id',
        'outcome',
        'amount',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
