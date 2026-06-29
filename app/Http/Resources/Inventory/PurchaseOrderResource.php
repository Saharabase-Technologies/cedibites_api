<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'verification_code' => $this->verification_code,

            'supplier_id' => $this->supplier_id,
            'supplier' => [
                'id' => $this->supplier->id,
                'code' => $this->supplier->code,
                'name' => $this->supplier->name,
                'phone' => $this->supplier->phone,
            ],

            'destination_location_id' => $this->destination_location_id,
            'destination_location' => [
                'id' => $this->destinationLocation->id,
                'code' => $this->destinationLocation->code,
                'name' => $this->destinationLocation->name,
                'type' => $this->destinationLocation->type,
            ],

            'status' => $this->status->value,
            'requires_approval' => (bool) $this->requires_approval,
            'expected_delivery_date' => optional($this->expected_delivery_date)->toDateString(),
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,

            'created_by_id' => $this->created_by,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),

            'approved_by_id' => $this->approved_by,
            'approved_by' => $this->approved_by && $this->relationLoaded('approvedBy') && $this->approvedBy
                ? ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]
                : null,
            'approved_at' => optional($this->approved_at)->toIso8601String(),

            'cancelled_by_id' => $this->cancelled_by,
            'cancelled_by' => $this->cancelled_by && $this->relationLoaded('cancelledBy') && $this->cancelledBy
                ? ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]
                : null,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),

            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),

            'estimated_total' => (float) $this->estimated_total,
            'actual_total' => (float) $this->actual_total,

            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
