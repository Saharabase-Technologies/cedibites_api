<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Requisition
 */
class RequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'requesting_location' => $this->whenLoaded('requestingLocation', fn () => $this->requestingLocation ? [
                'id' => $this->requestingLocation->id,
                'name' => $this->requestingLocation->name,
                'type' => $this->requestingLocation->type,
            ] : null),
            'source_type' => $this->source_type,
            'source_location' => $this->whenLoaded('sourceLocation', fn () => $this->sourceLocation ? [
                'id' => $this->sourceLocation->id,
                'name' => $this->sourceLocation->name,
                'type' => $this->sourceLocation->type,
            ] : null),
            'purpose' => $this->purpose,
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item' => $line->relationLoaded('item') && $line->item ? [
                    'id' => $line->item->id,
                    'name' => $line->item->name,
                    'unit' => $line->relationLoaded('unit') && $line->unit ? $line->unit->symbol : null,
                ] : null,
                'requested_qty' => (float) $line->requested_qty,
                'approved_qty' => $line->approved_qty !== null ? (float) $line->approved_qty : null,
            ])),
            'fulfilling_transfer' => $this->whenLoaded('fulfillingTransfer', fn () => $this->fulfillingTransfer ? [
                'id' => $this->fulfillingTransfer->id,
                'reference' => $this->fulfillingTransfer->reference,
                'status' => $this->fulfillingTransfer->status->value,
            ] : null),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            // Id as well as name: names are not unique (this deployment has two
            // Sarahs) and the UI has to decide "did I raise this?" reliably.
            'requested_by_id' => $this->requested_by,
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'rejected_at' => optional($this->rejected_at)->toIso8601String(),
            'fulfilled_at' => optional($this->fulfilled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
