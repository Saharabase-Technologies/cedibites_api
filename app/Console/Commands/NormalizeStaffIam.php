<?php

namespace App\Console\Commands;

use App\Enums\BranchRule;
use App\Enums\Role as RoleEnum;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring existing staff accounts in line with the role rules.
 *
 * Three things accumulated before those rules existed, and none of them are
 * visible from any screen:
 *
 *  1. Direct permission grants. The staff editor read a person's effective
 *     permissions, showed them as checkboxes, and wrote all of them back as
 *     grants attached to the user — so every account edited even once carries a
 *     copy of its role's permissions. That is how a manager kept getting
 *     `manage_employees` back after it was taken off the manager role, and it
 *     means changing someone's role today does not change what they can do.
 *
 *  2. Second roles. `assignRole` was used where `syncRoles` was meant, so
 *     promoting or rehiring left the old role attached. Permissions are the
 *     union of every role held; the badge on every screen shows only the first.
 *
 *  3. Branch assignments the role does not allow — an admin pinned to one
 *     branch, a manager running three.
 *
 * Dry run by default. 1 and 2 are repaired with --apply. 3 is only ever
 * reported: which single branch a three-branch manager should keep is not a
 * decision a script gets to make.
 */
class NormalizeStaffIam extends Command
{
    protected $signature = 'iam:normalize-staff
                            {--apply : Write the changes. Without this the command only reports.}';

    protected $description = 'Strip direct permission grants, collapse multi-role staff to one role, and report branch assignments that break the role rules';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $employees = Employee::with(['user.roles', 'user.permissions', 'branches'])
            ->whereHas('user')
            ->get();

        $this->info($apply ? 'Applying changes.' : 'Dry run — nothing will be written. Re-run with --apply.');
        $this->newLine();

        $strippedPermissions = $this->stripDirectPermissions($employees, $apply);
        $collapsedRoles = $this->collapseMultiRole($employees, $apply);
        $clearedBranches = $this->clearBranchesOnCompanyWideRoles($employees, $apply);
        $branchViolations = $this->reportBranchViolations($employees->fresh());

        $this->newLine();
        $this->info('Summary');
        $this->table(
            ['Check', 'Accounts affected', 'Action'],
            [
                ['Direct permission grants', $strippedPermissions, $apply ? 'revoked' : 'would revoke'],
                ['More than one role', $collapsedRoles, $apply ? 'collapsed to one' : 'would collapse to one'],
                ['Branch on a company-wide role', $clearedBranches, $apply ? 'cleared' : 'would clear'],
                ['Branch count breaks the role rule', $branchViolations, 'reported only — fix by hand'],
            ],
        );

        if (! $apply && ($strippedPermissions > 0 || $collapsedRoles > 0)) {
            $this->newLine();
            $this->comment('Re-run with --apply to write these changes.');
        }

        if ($branchViolations > 0) {
            $this->newLine();
            $this->warn('Branch violations need a person: which branch a multi-branch manager keeps is a decision, not a repair.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function stripDirectPermissions($employees, bool $apply): int
    {
        $affected = $employees->filter(fn (Employee $e) => $e->user->permissions->isNotEmpty());

        if ($affected->isEmpty()) {
            $this->info('Direct permission grants: none. ✓');

            return 0;
        }

        $this->warn("Direct permission grants: {$affected->count()} account(s).");

        foreach ($affected as $employee) {
            $names = $employee->user->permissions->pluck('name')->sort()->implode(', ');
            $role = $employee->user->getRoleNames()->first() ?? '(no role)';
            $this->line("  · {$employee->user->name} [{$role}] — {$employee->user->permissions->count()}: {$names}");

            if ($apply) {
                $employee->user->syncPermissions([]);
            }
        }

        return $affected->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function collapseMultiRole($employees, bool $apply): int
    {
        $affected = $employees->filter(fn (Employee $e) => $e->user->roles->count() > 1);

        if ($affected->isEmpty()) {
            $this->info('Accounts holding more than one role: none. ✓');

            return 0;
        }

        $this->warn("Accounts holding more than one role: {$affected->count()}.");

        foreach ($affected as $employee) {
            $held = $employee->user->roles->pluck('name');

            // Keep the most privileged. Dropping to the least would lock someone
            // out of the job they are actually doing; keeping the most is the
            // status quo for them, and it is the one an operator would notice
            // and correct if it is wrong.
            $keep = $held
                ->map(fn (string $name) => RoleEnum::tryFrom($name))
                ->filter()
                ->sortByDesc(fn (RoleEnum $role) => $role->privilegeRank())
                ->first();

            if (! $keep) {
                $this->line("  · {$employee->user->name} — holds {$held->implode(', ')}, none recognised. Skipped.");

                continue;
            }

            $dropping = $held->reject(fn (string $name) => $name === $keep->value);
            $this->line("  · {$employee->user->name} — keeping {$keep->value}, dropping {$dropping->implode(', ')}");

            if ($apply) {
                DB::transaction(function () use ($employee, $keep) {
                    $employee->user->syncRoles([$keep->value]);
                });
            }
        }

        return $affected->count();
    }

    /**
     * Detach branches from roles that have none.
     *
     * Safe to apply, unlike the count violations below, because a company-wide
     * role is not scoped by its branch pivot at all — every branch-aware read
     * asks User::isCompanyWide() first and treats these accounts as covering
     * everything. The rows are leftovers from when the form demanded a branch
     * for every role; they mean nothing and clearing them changes nothing
     * except that the account stops contradicting its own role.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function clearBranchesOnCompanyWideRoles($employees, bool $apply): int
    {
        $affected = $employees->filter(function (Employee $employee) {
            $roleName = $employee->user->getRoleNames()->first();
            $role = is_string($roleName) ? RoleEnum::tryFrom($roleName) : null;

            return $role?->branchRule() === BranchRule::None && $employee->branches->isNotEmpty();
        });

        if ($affected->isEmpty()) {
            $this->info('Branches attached to company-wide roles: none. ✓');

            return 0;
        }

        $this->warn("Branches attached to company-wide roles: {$affected->count()} account(s).");

        foreach ($affected as $employee) {
            $role = $employee->user->getRoleNames()->first();
            $names = $employee->branches->pluck('name')->implode(', ');
            $this->line("  · {$employee->user->name} [{$role}] — detaching {$names}");

            if ($apply) {
                $employee->branches()->detach();
            }
        }

        return $affected->count();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     */
    private function reportBranchViolations($employees): int
    {
        $violations = [];

        foreach ($employees as $employee) {
            $roleName = $employee->user->getRoleNames()->first();
            $role = is_string($roleName) ? RoleEnum::tryFrom($roleName) : null;

            if (! $role) {
                continue;
            }

            $count = $employee->branches->count();
            $rule = $role->branchRule();

            $problem = match ($rule) {
                // Handled by clearBranchesOnCompanyWideRoles above.
                BranchRule::None => null,
                BranchRule::ExactlyOne => $count !== 1 ? "needs exactly 1 branch, has {$count}" : null,
                BranchRule::OneOrMore => $count === 0 ? 'needs at least 1 branch, has none' : null,
            };

            if ($problem) {
                $violations[] = [
                    $employee->employee_no,
                    $employee->user->name,
                    $role->value,
                    $employee->branches->pluck('name')->implode(', ') ?: '—',
                    $problem,
                ];
            }
        }

        if ($violations === []) {
            $this->info('Branch assignments: all match their role rule. ✓');

            return 0;
        }

        $this->warn('Branch assignments breaking the role rule: '.count($violations).'.');
        $this->table(['Emp no', 'Name', 'Role', 'Branches', 'Problem'], $violations);

        return count($violations);
    }
}
