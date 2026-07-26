<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Returns StaffUser shape for frontend compatibility.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->getRoleNames();
        $role = $roles->first() ?? 'sales_staff';

        return [
            // NOTE: `id` is the EMPLOYEE id and stays that way — existing screens
            // depend on it. Anything comparing against an actor recorded on a
            // document (requested_by, sent_by, …) must use `user_id`, which is
            // what those columns actually hold.
            'id' => (string) $this->employee->id,
            'user_id' => $this->id,
            // Where this person may physically act: receive into, send out of.
            // null means "anywhere" (admins). A warehouse manager oversees every
            // location but works the warehouse, so this is narrower than the
            // read scope on purpose.
            'operating_location_ids' => $this->resource->operatingLocationIds(),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $role,
            'status' => $this->employee->status->value,
            'branches' => $this->employee->branches->map(fn ($branch) => [
                'id' => (string) $branch->id,
                'name' => $branch->name,
                'address' => $branch->address ?? '',
            ])->values()->all(),
            'roles' => $roles,
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'must_reset_password' => (bool) $this->must_reset_password,
        ];
    }
}
