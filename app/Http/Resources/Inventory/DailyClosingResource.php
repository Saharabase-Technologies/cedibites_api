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
        $lines = $this->whenLoaded('lines');
        $counted = $this->relationLoaded('lines')
            ? $this->lines->whereNotNull('counted_qty')
            : collect();

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
                'expected_qty' => (float) $line->expected_qty,
                'counted_qty' => $line->counted_qty !== null ? (float) $line->counted_qty : null,
                'variance' => $line->variance !== null ? (float) $line->variance : null,
            ])),
            // Summary — present whenever lines are loaded.
            'line_count' => $this->when($this->relationLoaded('lines'), fn () => $this->lines->count()),
            'counted_count' => $this->when($this->relationLoaded('lines'), fn () => $counted->count()),
            'discrepancy_count' => $this->when($this->relationLoaded('lines'), fn () => $counted->filter(fn ($l) => (float) $l->variance !== 0.0)->count()),
            'net_variance' => $this->when($this->relationLoaded('lines'), fn () => round((float) $counted->sum(fn ($l) => (float) $l->variance), 4)),
            'opened_by' => $this->whenLoaded('openedBy', fn () => $this->openedBy?->name),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->name),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
