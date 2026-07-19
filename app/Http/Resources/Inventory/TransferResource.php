<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Transfer
 */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'source_location' => $this->whenLoaded('sourceLocation', fn () => $this->sourceLocation ? [
                'id' => $this->sourceLocation->id,
                'name' => $this->sourceLocation->name,
                'type' => $this->sourceLocation->type,
            ] : null),
            'destination_location' => $this->whenLoaded('destinationLocation', fn () => $this->destinationLocation ? [
                'id' => $this->destinationLocation->id,
                'name' => $this->destinationLocation->name,
                'type' => $this->destinationLocation->type,
            ] : null),
            'parent_transfer_id' => $this->parent_transfer_id,
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
                'sent_qty' => $line->sent_qty !== null ? (float) $line->sent_qty : null,
                'received_qty' => $line->received_qty !== null ? (float) $line->received_qty : null,
                'unit_cost_at_time' => $line->unit_cost_at_time !== null ? (float) $line->unit_cost_at_time : null,
            ])),
            'dispute' => $this->whenLoaded('dispute', fn () => $this->dispute ? [
                'id' => $this->dispute->id,
                'status' => $this->dispute->status,
                'reason' => $this->dispute->reason,
                'discrepancy_qty' => (float) $this->dispute->discrepancy_qty,
                'corrective_transfer_id' => $this->dispute->corrective_transfer_id,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'sent_by' => $this->whenLoaded('sentBy', fn () => $this->sentBy?->name),
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'cancel_reason' => $this->cancel_reason,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'received_at' => optional($this->received_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
