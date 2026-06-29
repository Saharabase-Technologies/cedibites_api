<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\PurchaseItem
 */
class PurchaseItemResource extends JsonResource
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
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'ordered_qty' => $this->ordered_qty !== null ? (float) $this->ordered_qty : null,
            'received_qty' => (float) $this->received_qty,
            'variance' => $this->variance !== null ? (float) $this->variance : null,
            'variance_reason' => $this->variance_reason,
            'unit' => [
                'id' => $this->unit->id,
                'symbol' => $this->unit->symbol,
            ],
            'expected_unit_cost' => $this->expected_unit_cost !== null ? (float) $this->expected_unit_cost : null,
            'cost_variance' => $this->cost_variance !== null ? (float) $this->cost_variance : null,
            'unit_cost_paid' => (float) $this->unit_cost_paid,
            'line_total' => (float) $this->line_total,
        ];
    }
}
