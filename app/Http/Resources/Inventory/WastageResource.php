<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Wastage
 */
class WastageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),

            // Whether this record is what moves the stock, or a label on a loss
            // the ledger already carried. The UI needs to say which, or an
            // operator will reasonably assume every wastage deducts.
            'posts_stock' => $this->origin->postsStock(),

            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'type' => $this->location->type,
            ] : null),
            'disposal_location' => $this->whenLoaded('disposalLocation', fn () => $this->disposalLocation ? [
                'id' => $this->disposalLocation->id,
                'name' => $this->disposalLocation->name,
                'type' => $this->disposalLocation->type,
            ] : null),
            // Who saw it happen. Differs from `location` only on a refused
            // delivery, where the goods stay the sender's but the branch that
            // turned them away is the one holding the evidence.
            'claimant_location' => $this->whenLoaded('claimantLocation', fn () => $this->claimantLocation ? [
                'id' => $this->claimantLocation->id,
                'name' => $this->claimantLocation->name,
                'type' => $this->claimantLocation->type,
            ] : null),

            'total_value' => (float) $this->total_value,
            'threshold_amount' => $this->threshold_amount !== null ? (float) $this->threshold_amount : null,
            'over_threshold' => (float) $this->total_value > (float) ($this->threshold_amount ?? 0),
            'requires_approval' => (bool) $this->requires_approval,
            'requires_return' => (bool) $this->requires_return,

            'return_transfer' => $this->whenLoaded('returnTransfer', fn () => $this->returnTransfer ? [
                'id' => $this->returnTransfer->id,
                'reference' => $this->returnTransfer->reference,
                'status' => $this->returnTransfer->status->value,
            ] : null),

            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'notes' => $this->notes,

            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item' => $line->relationLoaded('item') && $line->item ? [
                    'id' => $line->item->id,
                    'name' => $line->item->name,
                    'unit' => $line->relationLoaded('unit') && $line->unit ? $line->unit->symbol : null,
                ] : null,
                'quantity' => (float) $line->quantity,
                'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                'line_value' => (float) $line->line_value,
                'reason' => $line->reason->value,
                'reason_label' => $line->reason->label(),
                'reason_note' => $line->reason_note,
                'posted' => $line->movement_id !== null,
            ])),
            'line_count' => $this->when($this->relationLoaded('lines'), fn () => $this->lines->count()),

            // Evidence, both sides of it. Visible to whoever can see the claim,
            // which is both ends of it - the branch that declared the loss and
            // the warehouse that has to answer for having supplied the goods.
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id,
                'stage' => $photo->stage,          // declared | inspection

                /*
                 * Three sizes, and the client picks by what it is drawing.
                 * `url` stays the ORIGINAL - it is the evidence, and "view full
                 * size" opens it. The other two fall back to it, so video rows,
                 * rows predating the derivatives, and anything GD could not read
                 * all keep rendering rather than showing a broken image.
                 */
                'url' => $photo->url,
                'thumb_url' => $photo->thumb_url ?? $photo->url,
                'display_url' => $photo->display_url ?? $photo->url,

                // A phone can send a clip as well as a still, so the gallery
                // has to know whether to render <img> or <video>. Derived here
                // rather than sniffed from the URL: an iPhone .mov and an
                // Android .webm share nothing but their mime prefix.
                'kind' => str_starts_with((string) $photo->mime_type, 'video/') ? 'video' : 'image',
                'mime_type' => $photo->mime_type,
                'size_bytes' => $photo->size_bytes,

                'caption' => $photo->caption,
                'uploaded_by' => $photo->relationLoaded('uploadedBy') ? $photo->uploadedBy?->name : null,
                'uploaded_by_id' => $photo->uploaded_by,
                'uploaded_at' => optional($photo->created_at)->toIso8601String(),
            ])),
            'photo_count' => $this->when($this->relationLoaded('photos'), fn () => $this->photos->count()),

            // Evidence can only be added while the claim is live; afterwards the
            // photo set is the record of what the decision was made on.
            'accepts_evidence' => $this->status->acceptsEvidence(),

            /**
             * Above the threshold the approver cannot sign off on nothing -
             * "show me the food that has gone bad". Surfaced so the UI can say
             * why the approve button is refused instead of only failing on POST.
             */
            'evidence_required' => (bool) $this->requires_approval
                && (float) $this->total_value > (float) ($this->threshold_amount ?? 0),

            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->name),
            'recorded_by_id' => $this->recorded_by,
            'recorded_at' => optional($this->recorded_at)->toIso8601String(),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->rejectedBy?->name),
            'rejected_at' => optional($this->rejected_at)->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'cancelled_by' => $this->whenLoaded('cancelledBy', fn () => $this->cancelledBy?->name),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
