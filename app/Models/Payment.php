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
        // Who took the money and on which shift (D9). See PaymentCollector.
        'collected_by',
        'collector_inferred',
        'shift_id',
        'source',
        // A part taken on an order that owes (D7). See TakePayment.
        'reference',
        'amount_tendered',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_gateway_response' => 'array',
            'paid_at' => 'datetime',
            'collector_inferred' => 'boolean',
            'amount_tendered' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Whoever took the money, which may not be whoever placed the order (D9). */
    public function collector(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'collected_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
