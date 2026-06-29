<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $table = 'inventory_purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'unit_id',
        'ordered_qty',
        'received_qty',
        'estimated_unit_cost',
        'line_total',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Outstanding qty still to be received against this line.
     */
    public function outstandingQty(): float
    {
        return max((float) $this->ordered_qty - (float) $this->received_qty, 0);
    }

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'estimated_unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }
}
