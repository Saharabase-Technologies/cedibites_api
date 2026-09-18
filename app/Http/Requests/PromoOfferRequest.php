<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A basket asking what comes off it: the dishes and their amounts, the branch,
 * and optionally a code and the phone number of whoever is ordering.
 */
class PromoOfferRequest extends FormRequest
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
            'branch_id' => ['required'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.menu_item_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
