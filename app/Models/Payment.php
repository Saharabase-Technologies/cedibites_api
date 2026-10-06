<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payments')
            ->logOnly(['payment_status', 'amount', 'payment_method'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'order_id',
        'customer_id',
        'payment_method',
        'payment_status',
        'amount',
        'transaction_id',
        // The Hubtel Collection Account the request went to. Null where Hubtel
        // was never asked, and on every payment from before branches had
        // accounts of their own, which all went to the company account.
        'hubtel_account_number',
        'payment_gateway_response',
        'paid_at',
        'refunded_at',
        'refund_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_gateway_response' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
