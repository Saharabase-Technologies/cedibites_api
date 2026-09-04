<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected static $recordEvents = ['updated'];

    /**
     * Valid status transitions. Each key maps to the statuses it can move to.
     */
    public const VALID_TRANSITIONS = [
        'received' => ['accepted', 'preparing', 'cancel_requested', 'cancelled'],
        'accepted' => ['preparing', 'cancel_requested', 'cancelled'],
        'preparing' => ['ready', 'ready_for_pickup', 'cancel_requested', 'cancelled'],
        'ready' => ['out_for_delivery', 'ready_for_pickup', 'completed', 'cancelled'],
        'ready_for_pickup' => ['completed', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'cancel_requested' => ['cancelled', 'received', 'accepted', 'preparing', 'ready'],
        'delivered' => [],
        'completed' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $status): bool
    {
        $allowed = self::VALID_TRANSITIONS[$this->status] ?? [];

        return in_array($status, $allowed, true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('orders')
            ->logOnly(['status', 'cancelled_at', 'cancelled_reason'])
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'order_number',
        'customer_id',
        'branch_id',
        'assigned_employee_id',
        'order_type',
        'order_source',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'contact_name',
        'contact_phone',
        'delivery_note',
        'subtotal',
        'delivery_fee',
        'delivery_fee_status',
        'delivery_fee_collected_at',
        'delivery_fee_collected_by',
        'service_charge',
        'discount',
        'promo_id',
        'promo_name',
        'total_amount',
        'status',
        'estimated_prep_time',
        'estimated_delivery_time',
        'actual_delivery_time',
        'cancelled_at',
        'cancelled_reason',
        'cancel_requested_by',
        'cancel_request_reason',
        'cancel_requested_at',
        'recorded_at',
        'receipt_printed_at',
        'receipt_print_count',
        'receipt_verification_code',
        'momo_number',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'delivery_latitude' => 'decimal:8',
            'delivery_longitude' => 'decimal:8',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'delivery_fee_collected_at' => 'datetime',
            'service_charge' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'estimated_prep_time' => 'integer',
            'estimated_delivery_time' => 'datetime',
            'actual_delivery_time' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'recorded_at' => 'datetime',
            'receipt_printed_at' => 'datetime',
            'internal_notes' => 'array',
        ];
    }

    /**
     * Stamp the prep-time estimate on the way in.
     *
     * On the model rather than at the call sites because there are three ways an
     * order gets created — OrderController, PosOrderController and
     * OrderCreationService — and this codebase has already been bitten once by
     * putting shared order logic in only some of them: the no-stock-no-sale gate
     * shipped guarding a door the till does not use, and sold 23 portions
     * against a balance of 6 four minutes after it went live. A `creating` hook
     * cannot be bypassed by a path nobody remembered to update.
     *
     * Only ever fills a gap. A caller that supplies its own estimate keeps it,
     * which is what the `nullable` rule in StoreOrderRequest is for.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order) {
            // Every order gets one, whatever path created it. Assigned here
            // rather than in the creation services because there are two of
            // those and a third would be easy to forget; an order with no code
            // is a receipt that can never be verified.
            if ($order->receipt_verification_code === null) {
                $order->receipt_verification_code = static::freshVerificationCode();
            }

            // Only for an order that is actually going to be prepared.
            //
            // The estimate was stamped on everything, so a bottle of water
            // carried a twenty-minute prep time it was never going to spend,
            // and that figure was read straight into the customer's SMS. An
            // order created already finished, or already waiting to be handed
            // over, has no preparation ahead of it and should not claim one.
            //
            // Nothing downstream loses a sample: PrepTimeEstimator measures
            // observed `preparing` to `ready` transitions in the status
            // history, and an order that skips the kitchen never records one.
            $skipsKitchen = in_array($order->status, ['completed', 'delivered', 'ready', 'ready_for_pickup'], true);

            if ($order->estimated_prep_time === null && ! $skipsKitchen) {
                $order->estimated_prep_time = app(\App\Domain\Orders\PrepTimeEstimator::class)
                    ->forBranch($order->branch_id ? (int) $order->branch_id : null);
            }
        });
    }

    /**
     * A receipt code nothing else is using.
     *
     * The column is unique, so a collision would throw on insert and lose the
     * order. Random(24) makes that vanishingly unlikely, but "vanishingly" is
     * not "never" across enough orders, and losing a sale to a coin flip is not
     * a trade worth making for one saved query.
     */
    public static function freshVerificationCode(): string
    {
        do {
            $code = \Illuminate\Support\Str::random(24);
        } while (static::where('receipt_verification_code', $code)->exists());

        return $code;
    }

    /**
     * Restaurant-collectible amount = order total minus the third-party delivery
     * fee. Delivery is collected by the rider on delivery and is never restaurant
     * revenue, so this is the amount the restaurant charges/records for goods.
     */
    public function getGoodsAmountAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->delivery_fee, 2);
    }

    /** Every receipt ever produced for this order, oldest first. */
    public function receiptPrints(): HasMany
    {
        return $this->hasMany(OrderReceiptPrint::class)->orderBy('printed_at');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function cancelRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
    }

    /**
     * Scope to only include orders with at least one completed or no_charge payment.
     * Used in operational views (kitchen, order manager) where only valid orders should appear.
     */
    public function scopePaymentConfirmed(Builder $query): void
    {
        $query->whereHas('payments', fn (Builder $q) => $q->whereIn('payment_status', ['completed', 'no_charge']));
    }
}
