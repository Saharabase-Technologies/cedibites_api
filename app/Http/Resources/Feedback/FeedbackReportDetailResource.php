<?php

namespace App\Http\Resources\Feedback;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full detail serializer — everything the one-screen triage view needs, including
 * the heavy diagnostic JSON the list resource omits.
 */
class FeedbackReportDetailResource extends JsonResource
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
            'transcript' => $this->transcript,
            'audio_url' => $this->audio_url,
            'replay_url' => $this->replay_url,
            'replay_id' => $this->replay_id,
            'screenshots' => $this->screenshots ?? [],
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'id' => $note->id,
                'route' => $note->route,
                'page_title' => $note->page_title,
                'body' => $note->body,
                'audio_url' => $note->audio_url,
                'transcript' => $note->transcript,
                'position' => $note->position,
            ])->values(), []),
            'breadcrumbs' => $this->breadcrumbs ?? [],
            'console_entries' => $this->console_entries ?? [],
            'network_entries' => $this->network_entries ?? [],
            'request_ids' => $this->request_ids ?? [],
            'client_meta' => $this->client_meta ?? null,
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter ? [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'related_count' => $this->when(isset($this->related_count), fn () => $this->related_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
