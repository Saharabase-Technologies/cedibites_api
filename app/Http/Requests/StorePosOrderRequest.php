<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePosOrderRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_option_id' => ['nullable', 'integer', 'exists:menu_item_options,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,card,wallet,ghqr,no_charge,manual_momo'],
            'is_manual_entry' => ['sometimes', 'boolean'],
            'recorded_at' => ['required_if:is_manual_entry,true', 'nullable', 'date', 'before_or_equal:now'],
            'momo_reference' => ['nullable', 'string', 'max:100'],
            'fulfillment_type' => ['required', 'string', 'in:dine_in,takeaway'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'customer_notes' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'momo_number' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'regex:/^(0[0-9]{9}|\+?233[0-9]{9})$/'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Branch is required',
            'branch_id.integer' => 'Branch ID must be a number',
            'branch_id.exists' => 'Invalid branch',
            'items.required' => 'Order items are required',
            'items.array' => 'Order items must be an array',
            'items.min' => 'Order must contain at least one item',
            'items.*.menu_item_id.required' => 'Menu item ID is required for each item',
            'items.*.menu_item_id.integer' => 'Menu item ID must be a number',
            'items.*.menu_item_id.exists' => 'Invalid menu item',
            'items.*.menu_item_option_id.integer' => 'Menu item option ID must be a number',
            'items.*.menu_item_option_id.exists' => 'Invalid menu item option',
            'items.*.quantity.required' => 'Quantity is required for each item',
            'items.*.quantity.integer' => 'Quantity must be a number',
            'items.*.quantity.min' => 'Quantity must be at least 1',
            'items.*.unit_price.required' => 'Unit price is required for each item',
            'items.*.unit_price.numeric' => 'Unit price must be a number',
            'items.*.unit_price.min' => 'Unit price cannot be negative',
            'payment_method.required' => 'Payment method is required',
            'payment_method.string' => 'Payment method must be a string',
            'payment_method.in' => 'Invalid payment method',
            'fulfillment_type.required' => 'Fulfillment type is required',
            'fulfillment_type.string' => 'Fulfillment type must be a string',
            'fulfillment_type.in' => 'Fulfillment type must be either dine_in or takeaway',
            'contact_name.required' => 'Contact name is required',
            'contact_name.string' => 'Contact name must be a string',
            'contact_name.max' => 'Contact name cannot exceed 255 characters',
            'contact_phone.required' => 'Contact phone is required',
            'contact_phone.string' => 'Contact phone must be a string',
            'contact_phone.max' => 'Contact phone cannot exceed 20 characters',
            'customer_notes.string' => 'Customer notes must be a string',
            'discount.numeric' => 'Discount must be a number',
            'discount.min' => 'Discount cannot be negative',
            'momo_number.required_if' => 'A MoMo number is required for mobile money payments',
            'momo_number.regex' => 'Mobile money number must be a valid Ghana phone number (e.g. 0241234567 or 233241234567)',
        ];
    }
}
