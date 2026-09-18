<?php

namespace App\Support\Promos;

use App\Models\Promo;

/**
 * The one discount an order carries, and how it got there.
 *
 * `codeBeaten` is true when the customer typed a code that was valid but
 * smaller than the offer the order already had. The bigger one stays, and the
 * screen says so, so nobody pays more for having typed a code.
 */
final class PromoOffer
{
    public function __construct(
        public readonly ?Promo $promo,
        public readonly float $discount,
        public readonly bool $codeBeaten = false,
    ) {}

    public static function none(): self
    {
        return new self(null, 0.0);
    }
}
