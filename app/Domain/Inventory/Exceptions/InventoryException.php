<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Domain-level guard failure (invalid state transition, business rule breach).
 * Controllers map this to a 422 response.
 */
class InventoryException extends RuntimeException
{
}
