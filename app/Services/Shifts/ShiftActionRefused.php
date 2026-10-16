<?php

namespace App\Services\Shifts;

use RuntimeException;

/** A Clock out or a confirm that cannot go ahead, with what to tell the person. */
class ShiftActionRefused extends RuntimeException
{
    /** @param  array<string, mixed>|null  $fresh  What the sheet should show now, when the money moved. */
    public function __construct(string $message, public readonly int $status = 422, public readonly ?array $fresh = null)
    {
        parent::__construct($message);
    }
}
