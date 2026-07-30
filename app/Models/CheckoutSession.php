<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_token',
        'branch_id',
        'session_type',
        // The channel the order came through — phone, WhatsApp, social, or the
        // till. Distinct from session_type, which is only online vs POS.
        'order_source',
        'status',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'special_instructions',
        'fulfillment_type',
        'payment_method',
        'momo_number',
        'items',
        'subtotal',
        'service_charge',
        'delivery_fee',
        'discount',
        'promo_id',
        'promo_name',
        'total_amount',
        'staff_id',
        'cart_id',
        'customer_id',
        'hubtel_transaction_id',
        'hubtel_checkout_url',
        'payment_gateway_response',
        'is_manual_entry',
        'recorded_at',
        'momo_reference',
        'amount_paid',
        'expires_at',
        'order_id',
        'last_momo_sent_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'payment_gateway_response' => 'array',
            'subtotal' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'is_manual_entry' => 'boolean',
            'recorded_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_momo_sent_at' => 'datetime',
        ];
    }

    // -- Relationships ---------------------

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'staff_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // -- Scopes ----------------------------

    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    public function scopeExpired(Builder $query): void
    {
        $query->where('status', 'pending')
            ->where('expires_at', '<', now());
    }

    public function scopeForStaff(Builder $query, int $staffId): void
    {
        $query->where('staff_id', $staffId);
    }

    public function scopeForBranch(Builder $query, int $branchId): void
    {
        $query->where('branch_id', $branchId);
    }

    public function scopeAwaitingPayment(Builder $query): void
    {
        $query->whereIn('status', ['pending', 'payment_initiated'])
            ->where('expires_at', '>', now());
    }

    // -- Helpers ---------------------------

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function convertToOrder(): Order
    {
        return app(\App\Services\OrderCreationService::class)->createFromCheckoutSession($this);
    }
}
