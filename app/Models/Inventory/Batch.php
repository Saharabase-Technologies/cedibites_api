<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Batch extends Model
{
    protected $table = 'inventory_batches';

    protected $fillable = [
        'item_id',
        'location_id',
        'purchase_item_id',
        'received_qty',
        'remaining_qty',
        'unit_cost',
        'expiry_date',
        'received_at',
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
            'received_qty' => 'decimal:4',
            'remaining_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'expiry_date' => 'date',
            'received_at' => 'datetime',
        ];
    }
}
