<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $table = 'inventory_purchase_items';

    protected $fillable = [
        'purchase_id',
        'item_id',
        'unit_id',
        'purchase_order_item_id',
        'ordered_qty',
        'received_qty',
        'variance',
        'variance_reason',
        'expected_unit_cost',
        'cost_variance',
        'unit_cost_paid',
        'line_total',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'variance' => 'decimal:4',
            'expected_unit_cost' => 'decimal:4',
            'cost_variance' => 'decimal:4',
            'unit_cost_paid' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }
}
