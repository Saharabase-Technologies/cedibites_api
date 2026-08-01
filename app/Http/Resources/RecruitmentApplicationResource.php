<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RecruitmentApplication
 */
class RecruitmentApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The route already gates on manage_employees, but the HR fields are the
        // most sensitive thing here and a resource outlives the route it was
        // written for.
        $canViewPii = (bool) $request->user()?->can('manage_employees');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'link' => $this->whenLoaded('link', fn () => [
                'id' => $this->link->id,
                'kind' => $this->link->kind->value,
                'posting' => $this->link->postingName(),
                'label' => $this->link->label,
                'assignable_roles' => array_map(
                    fn ($role) => ['value' => $role->value, 'label' => $role->label()],
                    $this->link->kind->assignableRoles(),
                ),
            ]),

            $this->mergeWhen($canViewPii, fn () => [
                'ssnit_number' => $this->ssnit_number,
                'ghana_card_id' => $this->ghana_card_id,
                'tin_number' => $this->tin_number,
                'date_of_birth' => $this->date_of_birth?->toDateString(),
                'nationality' => $this->nationality,
                'emergency_contact_name' => $this->emergency_contact_name,
                'emergency_contact_phone' => $this->emergency_contact_phone,
                'emergency_contact_relationship' => $this->emergency_contact_relationship,
            ]),

            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_user_id' => $this->created_user_id,

            'submitted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
