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
    /** The kitchen statuses a round moves through, in order. */
    public const VALID_TRANSITIONS = [
        'received' => ['accepted', 'preparing'],
        'accepted' => ['preparing'],
        'preparing' => ['ready'],
        'ready' => ['completed'],
        'completed' => [],
    ];

    /** A round still in the kitchen's or the pass's hands. */
    public const LIVE_STATUSES = ['received', 'accepted', 'preparing', 'ready'];

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
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'paid_later' => 'boolean',
            'receipt_printed_at' => 'datetime',
            'kitchen_slip_printed_at' => 'datetime',
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
}
