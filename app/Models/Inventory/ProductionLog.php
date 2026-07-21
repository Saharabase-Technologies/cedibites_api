<?php

namespace App\Models\Inventory;

use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionLog extends Model
{
    use ScopedToLocations;

    protected $table = 'inventory_production_logs';

    protected $fillable = [
        'reference',
        'location_id',
        'output_item_id',
        'output_unit_id',
        'output_qty',
        'output_batch_id',
        'input_cost_total',
        'output_unit_cost',
        'notes',
        'produced_by',
        'produced_at',
    ];

    public function inputs(): HasMany
    {
        return $this->hasMany(ProductionLogInput::class, 'production_log_id');
    }

    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'output_unit_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function producedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'produced_by');
    }

    protected function casts(): array
    {
        return [
            'output_qty' => 'decimal:4',
            'input_cost_total' => 'decimal:4',
            'output_unit_cost' => 'decimal:4',
            'produced_at' => 'datetime',
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
