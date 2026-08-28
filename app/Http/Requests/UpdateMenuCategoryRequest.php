<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuCategoryRequest extends FormRequest
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
        $category = $this->route('menu_category');
        $branchId = $this->input('branch_id') ?? $category?->branch_id;

        return [
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('menu_categories', 'name')
                    ->where('branch_id', $branchId)
                    ->ignore($category),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // False means nothing in this category needs cooking, so an order
            // made up entirely of its items skips the kitchen board. See
            // App\Services\Orders\PreparationRouter.
            'requires_preparation' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name already exists in this branch.',
        ];
    }
}
