<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLogInput extends Model
{
    protected $table = 'inventory_production_log_inputs';

    protected $fillable = [
        'production_log_id',
        'item_id',
        'unit_id',
        'quantity',
        'unit_cost_at_time',
        'line_cost',
    ];

    public function productionLog(): BelongsTo
    {
        return $this->belongsTo(ProductionLog::class, 'production_log_id');
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
            'quantity' => 'decimal:4',
            'unit_cost_at_time' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }
}
