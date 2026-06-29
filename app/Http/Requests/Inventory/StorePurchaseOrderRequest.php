<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by route middleware
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:inventory_suppliers,id'],
            'destination_location_id' => [
                'required',
                'integer',
                // POs may only be delivered to a warehouse (mother kitchen)
                Rule::exists('inventory_locations', 'id')->where('type', 'warehouse'),
            ],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.ordered_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_location_id.exists' => 'The destination must be a warehouse.',
            'items.required' => 'Add at least one line item.',
            'items.*.ordered_qty.gt' => 'Ordered quantity must be greater than zero.',
        ];
    }
}
