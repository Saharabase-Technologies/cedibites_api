<?php

namespace App\Support\Promos;

use App\Models\Promo;
use App\Models\PromoCode;

/**
 * The one discount an order carries, and how it got there.
 *
 * `codeBeaten` is true when the customer typed a code that was valid but
 * smaller than the offer the order already had. The bigger one stays, and the
 * screen says so, so nobody pays more for having typed a code.
 *
 * `appliedCode` is the code as it is written, when a code won: the shared code,
 * or the one-off code with its dash back in. `promoCode` is that one-off code,
 * which the session and then the order record so it cannot be used again.
 */
final class PromoOffer
{
    public function __construct(
        public readonly ?Promo $promo,
        public readonly float $discount,
        public readonly bool $codeBeaten = false,
        public readonly ?PromoCode $promoCode = null,
        public readonly ?string $appliedCode = null,
    ) {}

    public static function none(): self
    {
        return new self(null, 0.0);
    }
}
