<?php

namespace App\Models\Inventory;

use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Denormalized balance cache keyed by (item_id, location_id). No auto-increment
 * id and only an updated_at timestamp. Writes go through MovementPostingEngine
 * via the query builder (composite-key upsert under row lock); this model is for
 * reads/relations only — do not call find() on it.
 */
class StockBalance extends Model
{
    use ScopedToLocations;

    public const CREATED_AT = null;

    protected $table = 'inventory_stock_balances';

    public $incrementing = false;

    protected $primaryKey = 'item_id';

    protected $fillable = [
        'item_id',
        'location_id',
        'quantity',
        'weighted_avg_cost',
        'last_movement_id',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'weighted_avg_cost' => 'decimal:4',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['location_id'];
    }
}
