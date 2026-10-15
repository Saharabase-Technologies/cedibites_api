<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Items added to an order after it was placed (Phase 4b, D29).
 *
 * Round 1 is the order as placed and has no row. Each addition is numbered
 * from 2, records who added it, and goes to the kitchen as its own ticket
 * with its own status. The money stays on the order: one total, many
 * payments.
 */
class OrderRound extends Model
{
    /**
     * The kitchen statuses a round moves through, in order. A round is
     * cancelled on its own (D45), up to the pass, as an order is.
     */
    public const VALID_TRANSITIONS = [
        'received' => ['accepted', 'preparing', 'cancelled'],
        'accepted' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /** A round still in the kitchen's or the pass's hands. */
    public const LIVE_STATUSES = ['received', 'accepted', 'preparing', 'ready'];

    /**
     * Staff may ask for a round's cancel until it is ready, the same window
     * as an order's (D45). An admin may cancel one outright up to the pass.
     */
    public const CANCEL_REQUEST_STATUSES = ['received', 'accepted', 'preparing'];

    protected $fillable = [
        'order_id',
        'number',
        'added_by',
        'status',
        'paid_later',
        'subtotal',
        'total',
        'checkout_session_id',
        'receipt_printed_at',
        'kitchen_slip_printed_at',
        'cancel_requested_by',
        'cancel_request_reason',
        'cancel_requested_at',
        'cancelled_by',
        'cancelled_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'paid_later' => 'boolean',
            'receipt_printed_at' => 'datetime',
            'kitchen_slip_printed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::VALID_TRANSITIONS[$this->status] ?? [], true);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'added_by');
    }

    public function cancelRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancel_requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** A cancel has been asked for and not yet answered. */
    public function cancelAsked(): bool
    {
        return $this->cancel_requested_at !== null && $this->status !== 'cancelled';
    }
}
