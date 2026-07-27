<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Manager Scope Cleanup — one-shot production data fix (Branch Isolation, Phase 0).
 *
 * Re-scopes the Manager role to match the revised ownership model (RoleSeeder is
 * authoritative; this reconciles already-seeded envs where RoleSeeder's additive
 * givePermissionTo cannot remove a stale grant):
 *
 *   − manage_menu       every branch serves the same menu, so editing it is a
 *                       company-wide act. Replaced by menu.availability.manage,
 *                       which flips a dish on or off at his own branch and touches
 *                       no price — per-branch prices are the Admin's too.
 *   − manage_employees  no hiring, no role changes, no suspending anyone's access.
 *                       Replaced by employee.notes.manage so he keeps a record on
 *                       his own people without any power over them.
 *   − manage_branches   creating and deleting branches is the Admin's. Replaced by
 *                       branch.operate — open, close and extended access for the
 *                       branch he actually runs.
 *   − delete_orders     a deleted order drops out of revenue. Cancellation already
 *                       has a request-and-approve flow; he uses that.
 *
 * `manage_menu` and `manage_employees` are why a branch manager could rewrite every
 * branch's menu and promote himself to tech_admin. Run this before Phase 3 unifies
 * the menu, or one manager edits every branch at once.
 *
 * Direct grants matter as much as the role. EnsureUserHasPermission resolves through
 * `$user->can()`, which is satisfied by a permission attached straight to the user —
 * and EmployeeController::update has been syncing arbitrary permission arrays onto
 * users. Revoking from the role alone would leave those in place, so this strips the
 * four from every manager individually as well.
 *
 * Idempotent — revoke/grant are no-ops when the target state already holds, so it is
 * safe to re-run.
 *
 * Run once per already-seeded environment (after PermissionSeeder + RoleSeeder):
 *   php artisan db:seed --class=ManagerScopeCleanupSeeder --force
 */
class ManagerScopeCleanupSeeder extends Seeder
{
    /**
     * Powers the manager no longer holds.
     *
     * @return list<string>
     */
    private function revoked(): array
    {
        return [
            Permission::ManageMenu->value,
            Permission::ManageEmployees->value,
            Permission::ManageBranches->value,
            Permission::DeleteOrders->value,
        ];
    }

    /**
     * The narrow replacements.
     *
     * @return list<string>
     */
    private function granted(): array
    {
        return [
            Permission::MenuAvailabilityManage->value,
            Permission::EmployeeNotesManage->value,
            Permission::BranchOperate->value,
        ];
    }

    public function run(): void
    {
        $manager = Role::where('name', RoleEnum::Manager->value)
            ->where('guard_name', 'api')
            ->first();

        if (! $manager) {
            $this->command?->warn('No manager role on this environment — nothing to re-scope.');

            return;
        }

        $manager->revokePermissionTo($this->revoked());
        $manager->givePermissionTo($this->granted());

        $this->command?->info('Manager role re-scoped.');

        // Strip the same four from any manager carrying them directly. A direct grant
        // outlives a role revoke and would quietly preserve the escalation path.
        $stripped = 0;

        User::role(RoleEnum::Manager->value)
            ->with('permissions')
            ->chunkById(100, function ($users) use (&$stripped) {
                foreach ($users as $user) {
                    $direct = $user->permissions->pluck('name')->intersect($this->revoked());

                    if ($direct->isEmpty()) {
                        continue;
                    }

                    $user->revokePermissionTo($direct->all());
                    $stripped++;

                    $this->command?->line("  stripped direct [{$direct->implode(', ')}] from {$user->name}");
                }
            });

        $this->command?->info($stripped === 0
            ? 'No manager held these directly.'
            : "Stripped direct grants from {$stripped} manager(s).");

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
