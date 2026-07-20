<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationLine extends Model
{
    protected $table = 'inventory_reconciliation_lines';

    protected $fillable = [
        'cycle_id',
        'item_id',
        'unit_id',
        'system_qty',
        'counted_qty',
        'variance',
        'unit_cost',
        'variance_value',
        'over_threshold',
        'adjustment_movement_id',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ReconciliationCycle::class, 'cycle_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'variance' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'variance_value' => 'decimal:4',
            'over_threshold' => 'boolean',
        ];
    }
}
