<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->employee;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A branch is required of anyone who works at one. The call centre works
        // a shift and belongs to no branch — the branch is a property of each
        // order they place, not of where they sit — so for them it is genuinely
        // absent rather than missing. See User::isCompanyWide.
        $required = $this->user()?->isCompanyWide() ? 'nullable' : 'required';

        return [
            'branch_id' => [$required, 'integer', 'exists:branches,id'],
        ];
    }
}
