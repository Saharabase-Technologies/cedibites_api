<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\WastageReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WastageLine extends Model
{
    protected $table = 'inventory_wastage_lines';

    protected $fillable = [
        'wastage_id',
        'item_id',
        'unit_id',
        'quantity',
        'unit_cost',
        'line_value',
        'reason',
        'reason_note',
        'movement_id',
    ];

    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class, 'wastage_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    protected function casts(): array
    {
        return [
            'reason' => WastageReason::class,
            'quantity' => 'float',
            'unit_cost' => 'float',
            'line_value' => 'float',
        ];
    }
}
