<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\DailyClosing
 */
class DailyClosingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $counted = $this->relationLoaded('lines')
            ? $this->lines->whereNotNull('counted_qty')
            : collect();

        // A blind count. While a closing is open the ledger's expectation — and
        // therefore the variance, which gives it away — is withheld from
        // everyone, so what gets typed in is what was actually on the shelf.
        // The founder's rule: "they don't get to see the expected." Everything
        // is revealed once the count is completed and can no longer be edited.
        $blind = $this->status->isEditable();

        return [
            'id' => $this->id,
            'business_date' => optional($this->business_date)->toDateString(),
            'status' => $this->status->value,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'type' => $this->location->type,
            ] : null),
            'notes' => $this->notes,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item' => $line->relationLoaded('item') && $line->item ? [
                    'id' => $line->item->id,
                    'name' => $line->item->name,
                    'unit' => $line->relationLoaded('unit') && $line->unit ? $line->unit->symbol : null,
                ] : null,
                'expected_qty' => $blind ? null : (float) $line->expected_qty,
                'counted_qty' => $line->counted_qty !== null ? (float) $line->counted_qty : null,
                'variance' => $blind || $line->variance === null ? null : (float) $line->variance,
                'reason' => $line->reason?->value,
                'reason_label' => $line->reason?->label(),
                'reason_note' => $line->reason_note,
                'adjusted' => $line->adjustment_movement_id !== null,
            ])),
            'blind' => $blind,
            // Summary — present whenever lines are loaded. The variance figures
            // stay hidden until the count is closed, for the same reason.
            'line_count' => $this->when($this->relationLoaded('lines'), fn () => $this->lines->count()),
            'counted_count' => $this->when($this->relationLoaded('lines'), fn () => $counted->count()),
            'discrepancy_count' => $this->when($this->relationLoaded('lines'), fn () => $blind ? 0 : $counted->filter(fn ($l) => (float) $l->variance !== 0.0)->count()),
            'net_variance' => $this->when($this->relationLoaded('lines'), fn () => $blind ? 0.0 : round((float) $counted->sum(fn ($l) => (float) $l->variance), 4)),
            'wastage' => $this->whenLoaded('wastage', fn () => $this->wastage ? [
                'id' => $this->wastage->id,
                'reference' => $this->wastage->reference,
                'total_value' => (float) $this->wastage->total_value,
            ] : null),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy?->name),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->name),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
