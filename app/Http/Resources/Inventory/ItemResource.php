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
            // Option A - buy-in-packs-of (e.g. a "crate" of 30 pieces). Null when the
            // item is only ever bought in its base unit.
            'purchase_pack_label' => $this->purchase_pack_label,
            'purchase_pack_size' => $this->purchase_pack_size !== null ? (float) $this->purchase_pack_size : null,
            /*
             * The cost this item would ACTUALLY be valued at, for the locations
             * in scope.
             *
             * Two columns carry this name and only one is kept current.
             * `inventory_stock_balances.weighted_avg_cost` is written by
             * MovementPostingEngine on every movement and is what the domain
             * values against; `inventory_items.weighted_avg_cost` is only ever
             * written by PurchaseService, so anything that arrived by transfer,
             * production or adjustment sits at its default of 0.
             *
             * This used to serve the item column, so the wastage form priced
             * transferred goods at GHS 0.00 and told the user the loss was under
             * the threshold and would be written off on the spot - while the
             * server was about to value it properly and demand a return.
             *
             * The item column remains the fallback for an item currently holding
             * no stock anywhere: a last known price beats nothing.
             */
            'weighted_avg_cost' => (float) (
                $this->scoped_unit_cost ?? $this->weighted_avg_cost
            ),
            // Total quantity on hand across all locations (summed balance cache).
            'stock_on_hand' => (float) ($this->stock_on_hand ?? 0),
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
