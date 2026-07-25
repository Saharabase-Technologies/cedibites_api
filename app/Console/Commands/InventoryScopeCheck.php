<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Models\Branch;
use App\Models\Inventory\Location;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Explain why an IMS user can or cannot see inventory records.
 *
 * Location scoping fails silently by design — an out-of-scope record 404s
 * rather than 403s, so "requisition not found" is the same symptom whether the
 * user picked someone else's branch, has no branch assignment, or their branch
 * has no inventory location at all. This command names which one it is.
 *
 * Read-only.
 */
class InventoryScopeCheck extends Command
{
    protected $signature = 'inventory:scope-check
                            {user? : Email, phone or user id. Omit to audit branch↔location wiring only}';

    protected $description = 'Diagnose a user\'s inventory location scope and flag branches with no inventory location';

    public function handle(): int
    {
        $this->auditBranchWiring();

        $needle = $this->argument('user');

        if ($needle === null) {
            $this->newLine();
            $this->line('Pass an email/phone/id to also diagnose a specific user.');

            return self::SUCCESS;
        }

        $user = User::query()
            ->where('email', $needle)
            ->orWhere('phone', $needle)
            ->orWhere('id', is_numeric($needle) ? (int) $needle : 0)
            ->first();

        if (! $user) {
            $this->error("No user matches '{$needle}'.");

            return self::FAILURE;
        }

        return $this->auditUser($user);
    }

    /**
     * Every branch needs a satellite location or its manager is locked out. The
     * catalog seeder creates one per branch, but nothing provisions a location
     * for a branch added afterwards, so this drifts.
     */
    private function auditBranchWiring(): void
    {
        $this->info('── Branch → inventory location ──────────────────────────');

        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
        $locationsByBranch = Location::query()
            ->whereNotNull('branch_id')
            ->get(['id', 'code', 'name', 'branch_id'])
            ->groupBy('branch_id');

        $orphans = 0;

        foreach ($branches as $branch) {
            $locations = $locationsByBranch->get($branch->id);

            if ($locations === null || $locations->isEmpty()) {
                $orphans++;
                $this->line("  <fg=red>✗</> {$branch->name} (branch #{$branch->id}) — NO inventory location");

                continue;
            }

            $codes = $locations->map(fn ($l) => "{$l->code} #{$l->id}")->implode(', ');
            $this->line("  <fg=green>✓</> {$branch->name} (branch #{$branch->id}) — {$codes}");
        }

        $unlinked = Location::query()->whereNull('branch_id')->where('type', 'satellite')->get(['id', 'code', 'name']);

        foreach ($unlinked as $location) {
            $this->line("  <fg=yellow>!</> Satellite {$location->code} \"{$location->name}\" (#{$location->id}) has branch_id = NULL — invisible to every branch manager");
        }

        if ($orphans > 0) {
            $this->newLine();
            $this->warn("{$orphans} branch(es) have no inventory location. Managers of those branches can create requisitions but never read them back.");
        }
    }

    private function auditUser(User $user): int
    {
        $this->newLine();
        $this->info("── {$user->name} (user #{$user->id}) ─────────────────────");

        $this->line('  Roles: '.($user->getRoleNames()->implode(', ') ?: '<none>'));

        $unrestricted = $user->can(Permission::InventoryViewAllLocations->value);
        $this->line('  inventory.view_all_locations: '.($unrestricted ? 'YES — sees every location' : 'no — confined to own branches'));
        $this->line('  view_inventory_catalog: '.($user->can(Permission::ViewInventoryCatalog->value) ? 'yes' : '<fg=red>NO — cannot read any IMS screen</>'));
        $this->line('  inventory.requisition.create: '.($user->can(Permission::InventoryRequisitionCreate->value) ? 'yes' : 'no'));

        $employee = $user->employee;

        if (! $employee) {
            $this->newLine();
            $this->error('  No employee record — accessibleLocationIds() returns []. This user can read nothing in IMS.');

            return self::FAILURE;
        }

        $branches = $employee->branches()->get(['branches.id', 'branches.name']);
        $this->line('  Employee: '.$employee->employee_no);
        $this->line('  Assigned branches: '.($branches->map(fn ($b) => "{$b->name} #{$b->id}")->implode(', ') ?: '<fg=red><none></>'));

        $ids = $user->accessibleLocationIds();

        $this->newLine();

        if ($ids === null) {
            $this->line('  <fg=green>Accessible locations: ALL (unrestricted)</>');

            return self::SUCCESS;
        }

        if ($ids === []) {
            $this->error('  Accessible locations: NONE.');
            $this->line('  Every inventory list will be empty and every detail page will 404.');
            $this->newLine();
            $this->line('  Cause is one of:');
            $this->line('    • the employee has no branch assignment (see above), or');
            $this->line('    • the assigned branch has no inventory_locations row (see the audit above).');

            return self::FAILURE;
        }

        $locations = Location::query()->whereIn('id', $ids)->get(['id', 'code', 'name', 'type']);
        $this->line('  <fg=green>Accessible locations:</>');

        foreach ($locations as $location) {
            $this->line("    #{$location->id} {$location->code} — {$location->name} ({$location->type})");
        }

        $default = $user->defaultInventoryLocationId();
        $this->line('  Default requesting location: '.($default !== null ? "#{$default}" : '<none — user must choose, or has 0/several satellites>'));

        return self::SUCCESS;
    }
}
