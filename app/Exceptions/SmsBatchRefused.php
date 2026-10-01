<?php

namespace App\Exceptions;

use App\Enums\SmsFailureReason;

/**
 * Hubtel answered a batch with a clear no, and took nothing.
 *
 * Its own type because of what a caller may do next. A refusal means no message
 * left, so the same recipients can be sent to again without texting anybody
 * twice. Every other failure of a batch (a timeout, a dropped connection, an
 * answer we cannot read) leaves that open, and is thrown as a plain exception.
 */
class SmsBatchRefused extends \Exception
{
    public function __construct(
        string $message,
        public readonly SmsFailureReason $reason,
    ) {
        parent::__construct($message);
    }
}
