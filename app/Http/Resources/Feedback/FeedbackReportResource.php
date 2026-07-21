<?php

namespace App\Http\Resources\Feedback;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Light list serializer for the triage inbox — drops the bulky diagnostic JSON
 * (screenshots, breadcrumbs, console, network) so the list stays cheap.
 */
class FeedbackReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'severity' => $this->severity,
            'status' => $this->status,
            'route' => $this->route,
            'role_at_report' => $this->role_at_report,
            'description' => $this->description,
            'has_audio' => (bool) $this->audio_url,
            'screenshot_count' => is_array($this->screenshots) ? count($this->screenshots) : 0,
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
