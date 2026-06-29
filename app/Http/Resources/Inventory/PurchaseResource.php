<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Purchase
 */
class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->purchase_order_id && $this->relationLoaded('purchaseOrder') && $this->purchaseOrder
                ? ['id' => $this->purchaseOrder->id, 'reference' => $this->purchaseOrder->reference]
                : null,

            'supplier_id' => $this->supplier_id,
            'supplier' => [
                'id' => $this->supplier->id,
                'code' => $this->supplier->code,
                'name' => $this->supplier->name,
            ],
            'supplier_name' => $this->supplier_name,

            'destination_location_id' => $this->destination_location_id,
            'destination_location' => [
                'id' => $this->destinationLocation->id,
                'name' => $this->destinationLocation->name,
            ],

            'is_urgent_buy' => (bool) $this->is_urgent_buy,
            'urgent_buy_reason' => $this->urgent_buy_reason,
            'invoice_number' => $this->invoice_number,
            'notes' => $this->notes,

            'recorded_by_id' => $this->recorded_by,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),

            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),

            'total_paid' => (float) $this->total_paid,
            'received_at' => optional($this->received_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
