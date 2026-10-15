<?php

namespace App\Services\Payments;

/** A part that cannot be taken, worded for the cashier. */
class TakePaymentRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
