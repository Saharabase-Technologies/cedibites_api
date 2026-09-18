<?php

namespace App\Support\Promos;

use RuntimeException;

/**
 * A code somebody typed that this order cannot take.
 *
 * The message is written for the person holding the phone or standing at the
 * till, and is shown to them as it stands. `reason` is the machine half, so a
 * screen can react to a kind of refusal without reading the words.
 */
class PromoCodeRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
