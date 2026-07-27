<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A location's own reorder point for one item, overriding the item's global
 * figure. Either column may be null to inherit that half from the item.
 */
class ItemLocationThreshold extends Model
{
    protected $table = 'inventory_item_location_thresholds';

    protected $fillable = [
        'item_id',
        'location_id',
        'reorder_level',
        'min_threshold',
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
            'reorder_level' => 'decimal:3',
            'min_threshold' => 'decimal:3',
        ];
    }
}
