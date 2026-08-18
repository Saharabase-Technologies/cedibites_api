<?php

namespace App\Rules;

use App\Helpers\PhoneQuality;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A number somebody could actually be reached on.
 *
 * Until this existed, `contact_phone` was validated as `string|max:20` on all
 * three order-creation paths, while `momo_number` in the very same POS request
 * already carried a Ghana regex. So `0000000000` and `1234567890` saved
 * cleanly — which is most of the reason unreachable numbers are in the data at
 * all.
 *
 * Deliberately checks FORM only. A dialleable but invented number
 * (`0244444444`) passes here and is caught afterwards by the
 * `suspicious_customer_phone` rule instead. Blocking those at the till would
 * eventually refuse a real customer standing at the counter with no way round
 * it, and would teach staff to invent less obvious numbers — which is strictly
 * worse, because then nothing can spot them.
 */
class GhanaPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneQuality::isWellFormed($value)) {
            $fail('Enter a Ghana phone number, like 0241234567.');
        }
    }
}
