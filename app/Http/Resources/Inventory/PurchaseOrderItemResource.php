<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\PurchaseOrderItem
 */
class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item' => [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'base_unit' => [
                    'id' => $this->item->baseUnit->id,
                    'name' => $this->item->baseUnit->name,
                    'symbol' => $this->item->baseUnit->symbol,
                ],
            ],
            'ordered_qty' => (float) $this->ordered_qty,
            'received_qty' => (float) $this->received_qty,
            'unit' => [
                'id' => $this->unit->id,
                'symbol' => $this->unit->symbol,
            ],
            'estimated_unit_cost' => (float) $this->estimated_unit_cost,
            'line_total' => (float) $this->line_total,
        ];
    }
}
