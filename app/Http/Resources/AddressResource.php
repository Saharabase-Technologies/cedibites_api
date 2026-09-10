<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One saved delivery address.
 *
 * `customer_id` is deliberately not in here. It is the thing the controller
 * scopes every query by, so telling the browser about it would only invite
 * somebody to send it back.
 */
class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'full_address' => $this->full_address,
            'note' => $this->note,
            // Decimals arrive from Postgres as strings. Every consumer wants a
            // number, and NaN is a better answer than "5.69889" reaching a map.
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
