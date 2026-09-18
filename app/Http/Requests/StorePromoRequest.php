<?php

namespace App\Http\Requests;

use App\Models\Promo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage_menu') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:percentage,fixed_amount'],
            'value' => ['required', 'numeric', 'min:0'],
            'scope' => ['required', 'string', 'in:global,branch'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'applies_to' => ['required', 'string', 'in:order,items'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
            'accounting_code' => ['nullable', 'string', 'max:50'],
            'code' => [
                'nullable', 'string', 'regex:/^[A-Z0-9-]{3,20}$/',
                Rule::unique('promos', 'code')->whereNull('deleted_at'),
            ],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The code is compared in capitals with no spaces, the way it is stored, so
     * "cedi20" cannot sit beside "CEDI20" as a second promo.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Promo::normaliseCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A code is 3 to 20 letters, numbers or dashes.',
            'code.unique' => 'Another promo already uses that code.',
        ];
    }
}
