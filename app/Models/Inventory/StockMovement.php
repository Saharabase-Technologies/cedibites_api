<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger row. Never edit or soft-delete. Reversals are new rows
 * with opposite sign and parent_movement_id set.
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null; // append-only; created_at only

    protected $table = 'inventory_stock_movements';

    protected $fillable = [
        'item_id',
        'location_id',
        'quantity',
        'movement_type',
        'reference_type',
        'reference_id',
        'batch_id',
        'unit_cost_at_time',
        'user_id',
        'parent_movement_id',
        'idempotency_key',
        'occurred_at',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_at_time' => 'decimal:4',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
