<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ShortLink
 */
class ShortLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'label' => $this->label,
            'target_url' => $this->target_url,

            // Both forms, because they are used for different things and the
            // difference costs money. See ShortLink::smsUrl().
            'url' => $this->url(),
            'sms_url' => $this->smsUrl(),

            // Flagged in the list: this link wears our brand and points
            // somewhere that is not ours.
            'is_external' => $this->isExternal(),

            'click_count' => $this->click_count,

            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
