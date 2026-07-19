<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    protected $table = 'inventory_requisition_lines';

    protected $fillable = [
        'requisition_id',
        'item_id',
        'unit_id',
        'requested_qty',
        'approved_qty',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
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
            'approved_qty' => 'decimal:4',
        ];
    }
}
