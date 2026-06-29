<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // base permission enforced by route; urgent-buy checked in controller
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['nullable', 'integer', 'exists:inventory_purchase_orders,id'],
            'supplier_id' => ['required', 'integer', 'exists:inventory_suppliers,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'destination_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],

            'is_urgent_buy' => ['boolean'],
            'urgent_buy_reason' => ['nullable', 'required_if:is_urgent_buy,true', 'string', 'max:2000'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'received_at' => ['required', 'date'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer', 'exists:inventory_purchase_order_items,id'],
            'items.*.ordered_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.received_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.variance_reason' => ['nullable', 'string', 'max:1000'],
            'items.*.unit_cost_paid' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'urgent_buy_reason.required_if' => 'A reason is required for urgent buys.',
            'items.*.received_qty.gt' => 'Received quantity must be greater than zero.',
        ];
    }
}
