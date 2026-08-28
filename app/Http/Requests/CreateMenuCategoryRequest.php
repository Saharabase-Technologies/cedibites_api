<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMenuCategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('menu_categories', 'name')
                    ->where('branch_id', $this->input('branch_id')),
            ],
            'description' => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'slug' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'requires_preparation' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Branch is required.',
            'branch_id.exists' => 'Selected branch does not exist.',
            'name.required' => 'Category name is required.',
            'name.unique' => 'A category with this name already exists in this branch.',
        ];
    }
}
