<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $branchOverride = $this->relationLoaded('branchPrices')
            ? $this->branchPrices->firstWhere('branch_id', $this->menuItem?->branch_id)
            : null;

        return [
            'id' => $this->id,
            'menu_item_id' => $this->menu_item_id,
            'option_key' => $this->option_key,
            'option_label' => $this->option_label,
            'display_name' => $this->display_name,
            'price' => (float) ($branchOverride?->price ?? $this->price),
            'base_price' => (float) $this->price,
            // What the till charges when the order comes from Bolt. Null means
            // the dish is not on Bolt. Bolt's own menu page shows the same.
            'bolt_price' => $this->bolt_price !== null ? (float) $this->bolt_price : null,
            'bolt_promo_percent' => $this->bolt_promo_percent !== null ? (float) $this->bolt_promo_percent : null,
            'display_order' => $this->display_order,
            'is_available' => $branchOverride?->is_available ?? $this->is_available,
            'image_url' => ($media = $this->getFirstMedia('menu-item-options'))
                ? route('media.show', $media)
                : null,
            'thumbnail_url' => ($media = $this->getFirstMedia('menu-item-options'))
                ? route('media.show', ['media' => $media, 'conversion' => 'thumbnail'])
                : null,
            'branch_prices' => $this->whenLoaded('branchPrices', fn () => $this->branchPrices->map(fn ($bp) => [
                'branch_id' => $bp->branch_id,
                'price' => $bp->price !== null ? (float) $bp->price : null,
                'is_available' => $bp->is_available,
            ])->values()
            ),
        ];
    }
}
