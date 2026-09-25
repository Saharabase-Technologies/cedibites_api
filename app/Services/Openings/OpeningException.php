<?php

namespace App\Services\Openings;

use RuntimeException;

/**
 * A refusal the person at the till should read, worded for them.
 *
 * `details` carries whatever the screen needs to act on it, such as the lines
 * still unanswered or the food-safety items that failed.
 */
class OpeningException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly string $reason = 'opening_refused', public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
