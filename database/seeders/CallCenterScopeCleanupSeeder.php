<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Call Centre Scope Cleanup — one-shot production data fix.
 *
 * The call centre picks up the phone, takes the order, and places it against the
 * branch that will cook it. From that moment the order is the branch's: they
 * accept it, prepare it, and complete it. The agent is already on the next call.
 *
 * `update_orders` gave the agent all of that — accept, prepare, ready, complete —
 * on every branch in the company, which is neither their job nor something they
 * are in a position to know. It was granted because asking for a cancellation
 * rode on the same permission. That has been split out as
 * `order.cancel.request`, so the two can now be held separately, and the call
 * centre holds only the second: a customer rings back, the agent raises the
 * request, an admin decides.
 *
 * Direct grants matter as much as the role — EnsureUserHasPermission resolves
 * through `$user->can()`, which a permission attached straight to the user
 * satisfies. The staff editor used to sync arbitrary permission arrays onto
 * users, so revoking from the role alone would leave those in place. See
 * `iam:normalize-staff`, which strips direct grants across the board.
 *
 * Idempotent — safe to re-run.
 *
 * Run once per already-seeded environment (after PermissionSeeder + RoleSeeder):
 *   php artisan db:seed --class=CallCenterScopeCleanupSeeder --force
 */
class CallCenterScopeCleanupSeeder extends Seeder
{
    /**
     * @return list<string>
     */
    private function revoked(): array
    {
        return [Permission::UpdateOrders->value];
    }

    /**
     * @return list<string>
     */
    private function granted(): array
    {
        return [Permission::OrderCancelRequest->value];
    }

    public function run(): void
    {
        $callCenter = Role::where('name', RoleEnum::CallCenter->value)
            ->where('guard_name', 'api')
            ->first();

        if (! $callCenter) {
            $this->command?->warn('No call_center role on this environment — nothing to re-scope.');

            return;
        }

        $callCenter->revokePermissionTo($this->revoked());
        $callCenter->givePermissionTo($this->granted());

        $this->command?->info('Call centre role re-scoped: order handling out, cancel requests in.');

        $stripped = 0;

        User::role(RoleEnum::CallCenter->value)
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
            ? 'No call centre agent held update_orders directly.'
            : "Stripped direct grants from {$stripped} agent(s).");

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
