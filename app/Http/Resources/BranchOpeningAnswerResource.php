<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\BranchOpeningAnswer
 */
class BranchOpeningAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'section' => $this->section,
            'group' => $this->group,
            'label' => $this->label,
            'short' => $this->short,
            'help' => $this->help,
            'kind' => $this->kind,
            'weight' => $this->weight,
            'allows_na' => $this->allows_na,
            'answer' => $this->answer,
            'value' => $this->value,
            'note' => $this->note,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'answered_by' => $this->whenLoaded('answerer', fn () => $this->answerer?->name),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'resolution_note' => $this->resolution_note,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($p) => [
                'id' => $p->id,
                'stage' => $p->stage,
                'url' => $p->url,
                'thumb_url' => $p->thumb_url ?? $p->url,
                'mime_type' => $p->mime_type,
                'uploaded_by' => $p->uploaded_by,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
