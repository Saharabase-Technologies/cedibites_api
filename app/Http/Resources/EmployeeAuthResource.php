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
            'id' => (string) $this->employee->id,
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
