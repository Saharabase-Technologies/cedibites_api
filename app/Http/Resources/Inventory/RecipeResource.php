<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Recipe
 */
class RecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'menu_item_option_id' => $this->menu_item_option_id,
            'menu_item_option' => $this->whenLoaded('menuItemOption', fn () => $this->menuItemOption ? [
                'id' => $this->menuItemOption->id,
                'label' => $this->menuItemOption->display_name ?: $this->menuItemOption->option_label,
                'menu_item' => $this->menuItemOption->relationLoaded('menuItem') && $this->menuItemOption->menuItem ? [
                    'id' => $this->menuItemOption->menuItem->id,
                    'name' => $this->menuItemOption->menuItem->name,
                ] : null,
            ] : null),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'is_default' => (bool) $this->is_default,
            'status' => $this->status,
            'version' => (int) $this->version,
            'yield_qty' => (float) $this->yield_qty,
            'locked_by' => $this->whenLoaded('lockedBy', fn () => $this->lockedBy ? [
                'id' => $this->lockedBy->id,
                'name' => $this->lockedBy->name,
            ] : null),
            'locked_at' => optional($this->locked_at)->toIso8601String(),
            'ingredients' => $this->whenLoaded('ingredients', fn () => $this->ingredients->map(fn ($ing) => [
                'id' => $ing->id,
                'item_id' => $ing->item_id,
                'item' => $ing->relationLoaded('item') && $ing->item ? [
                    'id' => $ing->item->id,
                    'sku' => $ing->item->sku,
                    'name' => $ing->item->name,
                    'base_unit' => $ing->item->relationLoaded('baseUnit') && $ing->item->baseUnit ? [
                        'id' => $ing->item->baseUnit->id,
                        'name' => $ing->item->baseUnit->name,
                        'symbol' => $ing->item->baseUnit->symbol,
                    ] : null,
                ] : null,
                'unit' => $ing->relationLoaded('unit') && $ing->unit ? [
                    'id' => $ing->unit->id,
                    'symbol' => $ing->unit->symbol,
                ] : null,
                'quantity' => (float) $ing->quantity,
            ])),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
