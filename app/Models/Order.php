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
        'promo_code_id',
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
        'manual_entry_reason',
        // Pay later (D1 to D7). See the migration of 2026-10-15.
        'paid_later',
        'placed_unpaid_at',
        'fully_paid_at',
        'unpaid_alerted_at',
        'kitchen_slip_printed_at',
        // When it last started owing (Phase 4b). Cleared when paid.
        'owing_since',
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
            'paid_later' => 'boolean',
            'placed_unpaid_at' => 'datetime',
            'fully_paid_at' => 'datetime',
            'unpaid_alerted_at' => 'datetime',
            'kitchen_slip_printed_at' => 'datetime',
            'owing_since' => 'datetime',
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

    /** Items added after the order was placed, round 2 onwards (Phase 4b). */
    public function rounds(): HasMany
    {
        return $this->hasMany(OrderRound::class)->orderBy('number');
    }

    /**
     * On the kitchen's boards: the order's own status is live, or one of its
     * rounds is. An order served long ago comes back for its round only.
     */
    public function scopeLiveForKitchen(Builder $query, array $statuses): void
    {
        $roundStatuses = array_values(array_intersect($statuses, OrderRound::LIVE_STATUSES));

        $query->where(fn (Builder $q) => $q->whereIn('status', $statuses)
            ->orWhereHas('rounds', fn (Builder $r) => $r->whereIn('status', $roundStatuses ?: ['-'])));
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

    /**
     * Paid, or placed by staff with Pay later (D4).
     *
     * Placing a Pay later order is the staff member vouching for it, so the
     * kitchen and the Order Manager see it at once. An online order waiting on
     * its MoMo prompt is neither, and stays out.
     */
    public function scopeConfirmedOrPayLater(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->paymentConfirmed()->orWhere('paid_later', true));
    }

    /** The money in so far: completed payments only (D16). */
    public function getAmountPaidAttribute(): float
    {
        return round((float) $this->payments->where('payment_status', 'completed')->sum('amount'), 2);
    }

    /** What is still owed on the goods. Never below zero. */
    public function getBalanceDueAttribute(): float
    {
        if ($this->payments->contains('payment_status', 'no_charge')) {
            return 0.0;
        }

        return max(0.0, round($this->goods_amount - $this->amount_paid, 2));
    }

    /** not_paid, part_paid or paid. */
    public function getPaymentStateAttribute(): string
    {
        if ($this->balance_due <= 0) {
            return 'paid';
        }

        return $this->amount_paid > 0 ? 'part_paid' : 'not_paid';
    }

    /**
     * The secret half of a tracking link.
     *
     * An order number is one or two letters and three digits, so a whole prefix
     * is 999 codes and can be walked in an afternoon. That is fine for showing
     * a stranger which stage an order is on, and not fine for showing them the
     * address it is going to. The link we text carries this alongside the
     * number, and the address only renders for a request holding it.
     *
     * Derived rather than stored: an HMAC over the number and the id keyed on
     * the app key. No column, no migration, and every order ever placed already
     * has one. Rotating APP_KEY invalidates every outstanding link, which is
     * the correct behaviour for a secret keyed on it.
     */
    public function trackingToken(): string
    {
        return substr(
            hash_hmac('sha256', $this->order_number.'|'.$this->id, (string) config('app.key')),
            0,
            16,
        );
    }

    /** Where the customer follows this order, with the token that opens it. */
    public function trackingUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return "{$base}/orders/{$this->order_number}?t={$this->trackingToken()}";
    }
}
