<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\ReconciliationCycle
 */
class ReconciliationCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loaded = $this->relationLoaded('lines');
        $counted = $loaded ? $this->lines->whereNotNull('counted_qty') : collect();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'type' => $this->location->type,
            ] : null),
            'notes' => $this->notes,
            'net_variance_value' => $this->net_variance_value !== null ? (float) $this->net_variance_value : null,
            'threshold_amount' => $this->threshold_amount !== null ? (float) $this->threshold_amount : null,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item' => $line->relationLoaded('item') && $line->item ? [
                    'id' => $line->item->id,
                    'name' => $line->item->name,
                    'unit' => $line->relationLoaded('unit') && $line->unit ? $line->unit->symbol : null,
                ] : null,
                'system_qty' => (float) $line->system_qty,
                'counted_qty' => $line->counted_qty !== null ? (float) $line->counted_qty : null,
                'variance' => $line->variance !== null ? (float) $line->variance : null,
                'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                'variance_value' => $line->variance_value !== null ? (float) $line->variance_value : null,
                'over_threshold' => (bool) $line->over_threshold,
                'adjusted' => $line->adjustment_movement_id !== null,
                'reason' => $line->reason?->value,
                'reason_label' => $line->reason?->label(),
                'reason_note' => $line->reason_note,
            ])),
            // Summary — present whenever lines are loaded (always, from index/show).
            'line_count' => $this->when($loaded, fn () => $this->lines->count()),
            'counted_count' => $this->when($loaded, fn () => $counted->count()),
            'variance_line_count' => $this->when($loaded, fn () => $counted->filter(fn ($l) => (float) $l->variance !== 0.0)->count()),
            'over_threshold_count' => $this->when($loaded, fn () => $counted->filter(fn ($l) => (bool) $l->over_threshold)->count()),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy?->name),
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy?->name),
            'opened_at' => optional($this->opened_at)->toIso8601String(),
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
