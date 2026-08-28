<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'requires_preparation' => (bool) $this->requires_preparation,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'items_count' => $this->whenCounted('menuItems'),
        ];
    }
}
