<?php

namespace App\Http\Requests\Concerns;

use App\Models\Promo;
use App\Models\PromoCode;
use Closure;

/**
 * How a promo reaches an order, and its code, checked the same way on create
 * and on update.
 *
 * A code is compared the way the checkout matches it: capitals, no spaces, no
 * dashes. So "cedi20" cannot sit beside "CEDI20", "CEDI-20" cannot sit beside
 * "CEDI20", and a shared code cannot be the same word as a one-off voucher
 * somebody is already holding.
 */
trait ValidatesPromoRedemption
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function redemptionRules(bool $creating, ?Promo $ignore = null): array
    {
        return [
            'redemption' => [$creating ? 'required' : 'sometimes', 'string', 'in:'.implode(',', Promo::REDEMPTIONS)],
            'code' => [
                'nullable', 'string', 'regex:/^[A-Z0-9-]{3,20}$/',
                'required_if:redemption,'.Promo::SHARED_CODE,
                function (string $attribute, mixed $value, Closure $fail) use ($ignore) {
                    if (! is_string($value) || $value === '') {
                        return;
                    }
                    $key = PromoCode::lookupKey($value);
                    $taken = Promo::query()
                        ->where('redemption', Promo::SHARED_CODE)
                        ->whereRaw("replace(code, '-', '') = ?", [$key])
                        ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
                        ->exists();
                    if ($taken) {
                        $fail('Another promo already uses that code.');

                        return;
                    }
                    if (PromoCode::query()->where('lookup', $key)->exists()) {
                        $fail('That is already one of the one-off codes.');
                    }
                },
            ],
        ];
    }

    /**
     * Codes in capitals with no spaces. Only a shared-code promo keeps a code,
     * so switching a promo to one of the other kinds clears it.
     *
     * A client from before `redemption` existed sends a code or nothing, and
     * the kind is read off that, the way it always behaved.
     */
    protected function prepareRedemption(bool $creating): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Promo::normaliseCode($this->input('code'))]);
        }

        if (! $this->has('redemption') && ($creating || $this->has('code'))) {
            $this->merge(['redemption' => $this->filled('code') ? Promo::SHARED_CODE : Promo::AUTOMATIC]);
        }

        if ($this->has('redemption') && $this->input('redemption') !== Promo::SHARED_CODE) {
            $this->merge(['code' => null]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function redemptionMessages(): array
    {
        return [
            'code.regex' => 'A code is 3 to 20 letters, numbers or dashes.',
            'code.required_if' => 'Type the code customers will use.',
        ];
    }
}
