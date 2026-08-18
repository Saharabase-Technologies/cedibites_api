<?php

namespace App\Http\Requests;

use App\Rules\GhanaPhone;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderFromCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accepts frontend format; maps to backend fields in controller.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'order_type' => ['required', 'in:delivery,pickup'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20', new GhanaPhone],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'string', 'min:5'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'special_instructions' => ['nullable', 'string'],
            'payment_method' => ['required', 'in:mobile_money,cash'],
            'momo_number' => ['nullable', 'string', 'max:20'],
            'momo_network' => ['nullable', 'string', 'in:mtn,telecel,airteltigo'],
        ];
    }
}
