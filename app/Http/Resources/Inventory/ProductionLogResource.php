<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\ProductionLog
 */
class ProductionLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'output_item' => $this->whenLoaded('outputItem', fn () => $this->outputItem ? [
                'id' => $this->outputItem->id,
                'sku' => $this->outputItem->sku,
                'name' => $this->outputItem->name,
                'unit' => $this->outputItem->relationLoaded('baseUnit') && $this->outputItem->baseUnit
                    ? $this->outputItem->baseUnit->symbol : null,
            ] : null),
            'output_qty' => (float) $this->output_qty,
            'output_unit_cost' => (float) $this->output_unit_cost,
            'input_cost_total' => (float) $this->input_cost_total,
            'inputs' => $this->whenLoaded('inputs', fn () => $this->inputs->map(fn ($in) => [
                'id' => $in->id,
                'item_id' => $in->item_id,
                'item' => $in->relationLoaded('item') && $in->item ? [
                    'id' => $in->item->id,
                    'name' => $in->item->name,
                    'unit' => $in->relationLoaded('unit') && $in->unit ? $in->unit->symbol : null,
                ] : null,
                'quantity' => (float) $in->quantity,
                'line_cost' => (float) $in->line_cost,
            ])),
            'produced_by' => $this->whenLoaded('producedBy', fn () => $this->producedBy ? [
                'id' => $this->producedBy->id,
                'name' => $this->producedBy->name,
            ] : null),
            'produced_at' => optional($this->produced_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
