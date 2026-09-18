<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPromoRedemption;
use App\Models\Promo;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePromoRequest extends FormRequest
{
    use ValidatesPromoRedemption;

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
        $promo = $this->route('promo');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:percentage,fixed_amount'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'scope' => ['sometimes', 'string', 'in:global,branch'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
            'applies_to' => ['sometimes', 'string', 'in:order,items'],
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'accounting_code' => ['nullable', 'string', 'max:50'],
            ...$this->redemptionRules(creating: false, ignore: $promo instanceof Promo ? $promo : null),
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_customer' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareRedemption(creating: false);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->redemptionMessages();
    }
}
