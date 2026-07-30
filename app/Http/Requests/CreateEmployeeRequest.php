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

class CreateEmployeeRequest extends FormRequest
{
    use AppliesStaffRoleRules;

    /** Nothing to authorize beyond the route's `manage_employees` gate — the
     * role ceiling is a validation rule so the operator gets a reason, not a 403. */
    public function authorize(): bool
    {
        return true;
    }

    protected function targetEmployee(): ?Employee
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        // Canonicalise before the uniqueness check, not after. The unique rule is
        // the only thing stopping a staff account being claimed through a second
        // registration, and `024…` and `+233…` are the same phone to everyone
        // except a string comparison.
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $existing = User::where('phone', $value)->first();
                    if ($existing && $existing->employee) {
                        $fail('This phone number is already registered as a staff member.');
                    }
                },
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'password_mode' => ['nullable', 'string', 'in:auto,custom,prompt'],
            'branch_ids' => $this->branchRules(required: true),
            'branch_ids.*' => ['required', 'integer', 'exists:branches,id'],
            'role' => ['required', Rule::enum(Role::class), Rule::in(Role::assignableByAdmin())],
            'hire_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],

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
            'name.required' => 'Employee name is required.',
            'phone.required' => 'Phone number is required.',
            'email.unique' => 'This email is already registered.',
            'password.min' => 'Password must be at least 8 characters.',
            'branch_ids.required' => 'At least one branch is required for this role.',
            'branch_ids.size' => 'This role is assigned exactly one branch.',
            'branch_ids.*.exists' => 'Selected branch does not exist.',
            'role.required' => 'Role is required.',
            'role.in' => 'Platform Admin cannot be granted here. It is issued from the platform portal by an existing platform admin.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
