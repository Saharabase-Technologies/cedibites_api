<?php

namespace App\Domain\Staff;

use App\Models\Employee;

/**
 * What came back from hiring someone.
 *
 * `generatedPassword` is non-null only when the system chose the password and
 * the admin has to pass it on. It is null when the admin typed it (they know it)
 * and null when the holder chose it themselves (it is not the admin's to read).
 */
final readonly class ProvisionedEmployee
{
    public function __construct(
        public Employee $employee,
        public ?string $generatedPassword,
    ) {}
}
