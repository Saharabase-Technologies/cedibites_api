<?php

namespace App\Http\Requests;

use App\Rules\GhanaPhone;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
            'order_number' => ['required', 'string', 'max:20', 'unique:orders,order_number'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'branch_id' => ['required', 'exists:branches,id'],
            'assigned_employee_id' => ['nullable', 'exists:employees,id'],
            'order_type' => ['required', 'in:delivery,pickup'],
            'order_source' => ['required', 'in:online,phone,whatsapp,instagram,facebook,pos'],
            'delivery_address' => ['nullable', 'string'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20', new GhanaPhone],
            'delivery_note' => ['nullable', 'string'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'delivery_fee' => ['numeric', 'min:0'],
            'tax_rate' => ['numeric', 'min:0'],
            'tax_amount' => ['numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['in:received,preparing,ready,out_for_delivery,delivered,ready_for_pickup,completed,cancelled'],
            'estimated_prep_time' => ['nullable', 'integer', 'min:0'],
            'estimated_delivery_time' => ['nullable', 'date'],
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
            'order_number.required' => 'Order number is required',
            'order_number.unique' => 'This order number already exists',
            'branch_id.required' => 'Branch is required',
            'branch_id.exists' => 'Selected branch does not exist',
            'order_type.required' => 'Order type is required',
            'order_type.in' => 'Order type must be either delivery or pickup',
            'order_source.required' => 'Order source is required',
            'contact_name.required' => 'Contact name is required',
            'contact_phone.required' => 'Contact phone is required',
            'subtotal.required' => 'Subtotal is required',
            'total_amount.required' => 'Total amount is required',
        ];
    }
}
