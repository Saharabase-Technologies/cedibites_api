<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferLine extends Model
{
    protected $table = 'inventory_transfer_lines';

    protected $fillable = [
        'transfer_id',
        'item_id',
        'unit_id',
        'requested_qty',
        'sent_qty',
        'received_qty',
        'unit_cost_at_time',
        'sent_allocations',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
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
            'requested_qty' => 'decimal:4',
            'sent_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'unit_cost_at_time' => 'decimal:4',
            'sent_allocations' => 'array',
        ];
    }
}
