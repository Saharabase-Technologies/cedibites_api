<?php

namespace App\Http\Requests;

use App\Enums\EmployeeStatus;
use App\Enums\Role;
use App\Http\Requests\Concerns\AppliesStaffRoleRules;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    use AppliesStaffRoleRules;

    /**
     * Two things nobody with `manage_employees` gets to do, however senior.
     *
     * Changing your own role is how one call turned an administrator into a
     * platform admin. There is no legitimate version of it — every real
     * promotion is somebody else's decision about you — so it is refused
     * outright rather than ceilinged. Editing an account that already holds
     * tech_admin is refused for the same reason from the other direction: an
     * administrator who cannot grant the role must not be able to take it, move
     * it, or suspend the person holding it either.
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        $employee = $this->targetEmployee();

        if (! $actor || ! $employee) {
            return true;
        }

        if ($this->has('role') && $employee->user_id === $actor->id) {
            abort(403, 'You cannot change your own role. Ask another administrator.');
        }

        $targetIsTechAdmin = $employee->user?->hasRole(Role::TechAdmin->value) ?? false;

        if ($targetIsTechAdmin && ! $actor->hasRole(Role::TechAdmin->value)) {
            abort(403, 'Only a platform admin can edit a platform admin account.');
        }

        return true;
    }

    protected function targetEmployee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => User::normalizePhone($this->input('phone')) ?? $this->input('phone')]);
        }

        $this->applyRoleRules();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->targetEmployee()?->user_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'string', Rule::unique('users', 'phone')->ignore($userId)],
            'branch_ids' => $this->branchRules(required: false),
            'branch_ids.*' => ['required', 'integer', 'exists:branches,id'],
            'role' => ['sometimes', Rule::enum(Role::class), Rule::in(Role::assignableByAdmin())],
            'status' => ['sometimes', Rule::enum(EmployeeStatus::class)],
            'hire_date' => ['sometimes', 'nullable', 'date'],

            // HR Information
            'ssnit_number' => ['nullable', 'string', 'max:255'],
            'ghana_card_id' => ['nullable', 'string', 'max:255'],
            'tin_number' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:255'],

            // Emergency Contact
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateBranchCardinality($v));
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already registered.',
            'email.unique' => 'This email is already registered.',
            'branch_ids.size' => 'This role is assigned exactly one branch.',
            'branch_ids.min' => 'This role must be assigned at least one branch.',
            'branch_ids.*.exists' => 'Selected branch does not exist.',
            'role.in' => 'Platform Admin cannot be granted here. It is issued from the platform portal by an existing platform admin.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
