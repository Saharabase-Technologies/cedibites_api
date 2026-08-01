<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RecruitmentLink
 */
class RecruitmentLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'posting' => $this->postingName(),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'label' => $this->label,

            // The roles this link may produce, so the approval picker never has
            // to work it out and cannot drift from Role::branchRule().
            'assignable_roles' => array_map(
                fn ($role) => ['value' => $role->value, 'label' => $role->label()],
                $this->kind->assignableRoles(),
            ),

            'expires_at' => $this->expires_at->toIso8601String(),
            'is_expired' => $this->isExpired(),

            'applications_count' => $this->whenCounted('applications'),
            'pending_applications_count' => $this->whenCounted('pendingApplications'),

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
