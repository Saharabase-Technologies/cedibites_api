<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Guarantee the identities in config('iam.tech_admin_bootstrap') hold tech_admin.
 *
 * `tech_admin` is issued only from the platform portal by someone who already
 * holds it — a closed loop that needs a starting point. This is it, and it is
 * idempotent: run it on every deploy and it is a no-op once the accounts are
 * right.
 *
 * It grants a role to an account that already exists. It will not create a user,
 * mint a password or set a passcode — a platform admin account appearing from a
 * deploy script is exactly the thing this whole exercise is closing off. If the
 * identity is not found, that is reported and skipped.
 */
class TechAdminBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $identifiers = config('iam.tech_admin_bootstrap', []);

        foreach ($identifiers as $identifier) {
            $user = $this->findUser($identifier);

            if (! $user) {
                $this->command?->warn("  tech_admin bootstrap: no user matches \"{$identifier}\" — skipped.");

                continue;
            }

            if ($user->hasRole(RoleEnum::TechAdmin->value)) {
                $this->command?->info("  tech_admin bootstrap: {$user->name} already holds tech_admin.");

                continue;
            }

            // One role per user — see EmployeeController::store.
            $user->syncRoles([RoleEnum::TechAdmin->value]);
            $user->syncPermissions([]);

            activity('platform')
                ->performedOn($user)
                ->event('admin_created')
                ->withProperties(['source' => 'bootstrap', 'identifier' => $identifier])
                ->log("Bootstrapped {$user->name} as platform admin");

            $this->command?->info("  tech_admin bootstrap: granted tech_admin to {$user->name}.");
        }
    }

    private function findUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        $phone = User::normalizePhone($identifier);

        return User::where('phone', $phone)
            ->orWhere('phone', $identifier)
            ->first();
    }
}
