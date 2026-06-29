<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'integer', 'exists:inventory_suppliers,id'],
            'destination_location_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('inventory_locations', 'id')->where('type', 'warehouse'),
            ],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', 'exists:inventory_items,id'],
            'items.*.ordered_qty' => ['required_with:items', 'numeric', 'gt:0'],
            'items.*.estimated_unit_cost' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
