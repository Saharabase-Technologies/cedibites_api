<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'category_id' => ['nullable', 'exists:menu_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'unique:menu_items,slug,NULL,id,branch_id,'.$this->input('branch_id'),
            ],
            'description' => ['nullable', 'string'],
            'is_available' => ['boolean'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', 'exists:menu_tags,id'],
            'pricing_type' => ['nullable', 'string', 'in:simple,options'],
            'price' => ['nullable', 'numeric', 'min:0', 'required_if:pricing_type,simple'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Branch is required',
            'branch_id.exists' => 'Selected branch does not exist',
            'category_id.exists' => 'Selected category does not exist',
            'name.required' => 'Menu item name is required',
            'slug.required' => 'Slug is required',
            'slug.unique' => 'A menu item with this name already exists in this branch',
        ];
    }
}
