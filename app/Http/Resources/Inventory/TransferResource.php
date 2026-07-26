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
                // Arrived and turned away. Distinct from anything missing: these
                // went back to the sender rather than vanishing.
                'refused_qty' => $line->refused_qty !== null ? (float) $line->refused_qty : null,
                'refuse_reason' => $line->refuse_reason?->value,
                'refuse_reason_label' => $line->refuse_reason?->label(),
                'refuse_note' => $line->refuse_note,
                'unit_cost_at_time' => $line->unit_cost_at_time !== null ? (float) $line->unit_cost_at_time : null,
            ])),
            'dispute' => $this->whenLoaded('dispute', fn () => $this->dispute ? [
                'id' => $this->dispute->id,
                'status' => $this->dispute->status,
                // 'corrective' | 'written_off' | null (resolved before the
                // distinction existed, or nothing to write off)
                'resolution' => $this->dispute->resolution,
                'reason' => $this->dispute->reason,
                'discrepancy_qty' => (float) $this->dispute->discrepancy_qty,
                'written_off_qty' => (float) $this->dispute->written_off_qty,
                'corrective_transfer_id' => $this->dispute->corrective_transfer_id,
            ] : null),
            // The full corrective chain, oldest first — set by the show endpoint.
            'lineage' => $this->when(isset($this->lineage), fn () => $this->lineage),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'sent_by' => $this->whenLoaded('sentBy', fn () => $this->sentBy?->name),
            // Id too — names are not unique, and "did I send this?" gates the
            // receive action.
            'sent_by_id' => $this->sent_by,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->name),
            'reject_reason' => $this->reject_reason,
            'reject_reason_code' => $this->reject_reason_code,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'cancel_reason' => $this->cancel_reason,
            // Set when this transfer is the return leg carrying goods declared
            // bad back to the warehouse for inspection.
            'wastage' => $this->whenLoaded('wastage', fn () => $this->wastage ? [
                'id' => $this->wastage->id,
                'reference' => $this->wastage->reference,
                'status' => $this->wastage->status->value,
            ] : null),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'received_at' => optional($this->received_at)->toIso8601String(),
            'rejected_at' => optional($this->rejected_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
