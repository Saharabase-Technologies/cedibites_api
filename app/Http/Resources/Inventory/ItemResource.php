<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Inventory\Item
 */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'base_unit_id' => $this->base_unit_id,
            'base_unit' => $this->whenLoaded('baseUnit', fn () => [
                'id' => $this->baseUnit->id,
                'name' => $this->baseUnit->name,
                'symbol' => $this->baseUnit->symbol,
            ]),
            'default_supplier_id' => $this->default_supplier_id,
            'default_supplier' => $this->whenLoaded('defaultSupplier', fn () => $this->defaultSupplier ? [
                'id' => $this->defaultSupplier->id,
                'name' => $this->defaultSupplier->name,
            ] : null),
            'storage_type' => $this->storage_type,
            'is_consumable' => (bool) $this->is_consumable,
            'expiry_tracked' => (bool) $this->expiry_tracked,
            'reorder_level' => $this->reorder_level !== null ? (float) $this->reorder_level : null,
            'min_threshold' => $this->min_threshold !== null ? (float) $this->min_threshold : null,
            'weighted_avg_cost' => (float) $this->weighted_avg_cost,
            // Total quantity on hand across all locations (summed balance cache).
            'stock_on_hand' => (float) ($this->stock_on_hand ?? 0),
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
