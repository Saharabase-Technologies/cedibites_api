<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Erase a branch's operating data — orders, sessions, shifts, menu placement,
 * stock — without erasing anybody's account.
 *
 * Written for retiring a test branch from production. The reason it is a
 * command and not a few DELETEs typed into a console is that the obvious
 * version of this job is wrong in three separate ways, and none of them are
 * visible from a table grid:
 *
 *  - **Staff are unlinked, never deleted.** `orders.assigned_employee_id` is
 *    ON DELETE SET NULL, and staff attached to a test branch have served real
 *    orders at real branches — on production, one of them has 486. Deleting
 *    that employee silently blanks who served all 486. This command only ever
 *    removes `employee_branch` rows.
 *
 *  - **The branch row itself is deactivated, not deleted.** `menu_items`,
 *    `menu_categories`, `menu_add_ons` and `inventory_recipes` all hold
 *    `branch_id` with ON DELETE CASCADE, and since the menu was unified there
 *    is one dish row serving every branch. Deleting a branch that happens to
 *    own a dish row would delete that dish from the whole business. The
 *    command refuses outright if the branch owns any such row, and even then
 *    stops short of removing the branch.
 *
 *  - **The inventory location is emptied, not dropped.** Seventeen tables
 *    reference `inventory_locations` and most are ON DELETE RESTRICT, so
 *    dropping it either fails or requires shredding purchase orders, transfers
 *    and reconciliations that are somebody's audit trail.
 *
 * Dry run unless --apply. Everything happens in one transaction.
 */
class BranchPurge extends Command
{
    protected $signature = 'branch:purge
                            {--branch= : Branch id to purge (required)}
                            {--apply : Write the deletions. Without this the command only reports.}
                            {--force : Skip the typed confirmation. For scripted use only.}';

    protected $description = "Erase a branch's orders, sessions, shifts, menu placement and stock, leaving accounts intact";

    /**
     * Tables that hold `branch_id` with ON DELETE CASCADE and contain data
     * shared with the rest of the business. A row here means removing the
     * branch would take company-wide records with it.
     *
     * @var array<int, string>
     */
    private const SHARED_CASCADE_TABLES = [
        'menu_items',
        'menu_categories',
        'menu_add_ons',
        'menu_item_option_branch_prices',
        'inventory_recipes',
        'promo_branches',
    ];

    public function handle(): int
    {
        $branchId = (int) $this->option('branch');

        if ($branchId <= 0) {
            $this->error('--branch is required.');

            return self::FAILURE;
        }

        $branch = Branch::find($branchId);

        if (! $branch) {
            $this->error("No branch #{$branchId}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Branch #{$branch->id} — {$branch->name}");

        if (! $this->assertHoldsNoSharedData($branch->id)) {
            return self::FAILURE;
        }

        $locationIds = DB::table('inventory_locations')->where('branch_id', $branch->id)->pluck('id');
        $orderIds = DB::table('orders')->where('branch_id', $branch->id)->pluck('id');

        $plan = $this->buildPlan($branch->id, $orderIds, $locationIds);

        $this->reportPlan($plan);
        $this->reportWhatSurvives($branch->id, $orderIds);

        $total = array_sum(array_column($plan, 'count'));

        if ($total === 0) {
            $this->newLine();
            $this->line('  <fg=green>Nothing to purge — this branch holds no operating data.</>');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('  Dry run. Nothing was deleted. Re-run with --apply to carry this out.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirmByName($branch)) {
            $this->line('  Aborted.');

            return self::FAILURE;
        }

        $deleted = $this->runPurge($plan, $branch, $locationIds);

        $this->newLine();
        $this->info("  Deleted {$deleted} row(s). Branch and inventory location deactivated.");
        $this->line('  Accounts, customers and menu records were not touched.');

        return self::SUCCESS;
    }

    /**
     * Refuse to proceed if this branch owns rows that the rest of the business
     * shares. Reporting it is the whole point — the danger is invisible
     * otherwise.
     */
    private function assertHoldsNoSharedData(int $branchId): bool
    {
        $offenders = [];

        foreach (self::SHARED_CASCADE_TABLES as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $count = DB::table($table)->where('branch_id', $branchId)->count();

            if ($count > 0) {
                $offenders[] = "{$table} ({$count})";
            }
        }

        if ($offenders === []) {
            return true;
        }

        $this->newLine();
        $this->error('  This branch owns records that other branches share:');

        foreach ($offenders as $offender) {
            $this->line("    · {$offender}");
        }

        $this->newLine();
        $this->line('  These tables cascade on branch deletion, and since the menu was unified a');
        $this->line('  single row serves every branch — removing them here would remove them');
        $this->line('  everywhere. Re-point these rows at another branch first.');

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $orderIds
     * @param  \Illuminate\Support\Collection<int, int>  $locationIds
     * @return array<int, array{label: string, count: int, delete: callable}>
     */
    private function buildPlan(int $branchId, $orderIds, $locationIds): array
    {
        $plan = [];

        $byBranch = function (string $table, string $label) use ($branchId, &$plan) {
            if (! $this->tableExists($table)) {
                return;
            }

            $plan[] = [
                'label' => $label,
                'count' => DB::table($table)->where('branch_id', $branchId)->count(),
                'delete' => fn () => DB::table($table)->where('branch_id', $branchId)->delete(),
            ];
        };

        $byOrder = function (string $table, string $label) use ($orderIds, &$plan) {
            if (! $this->tableExists($table) || $orderIds->isEmpty()) {
                return;
            }

            $plan[] = [
                'label' => $label,
                'count' => DB::table($table)->whereIn('order_id', $orderIds)->count(),
                // Cascades from `orders` anyway; deleted explicitly so the
                // reported figure is what actually happened rather than a
                // side effect nobody sees.
                'delete' => fn () => DB::table($table)->whereIn('order_id', $orderIds)->delete(),
            ];
        };

        $byLocation = function (string $table, string $label) use ($locationIds, &$plan) {
            if (! $this->tableExists($table) || $locationIds->isEmpty()) {
                return;
            }

            $plan[] = [
                'label' => $label,
                'count' => DB::table($table)->whereIn('location_id', $locationIds)->count(),
                'delete' => fn () => DB::table($table)->whereIn('location_id', $locationIds)->delete(),
            ];
        };

        // Children before parents. `orders` cascades, but the order here keeps
        // the count honest and does not depend on the schema staying that way.
        $byOrder('shift_orders', 'Shift-order links');
        $byOrder('payments', 'Payments');
        $byOrder('order_status_history', 'Order status history');
        $byOrder('order_items', 'Order items');
        $byBranch('orders', 'Orders');
        $byBranch('checkout_sessions', 'Checkout sessions');
        $byBranch('shifts', 'Shifts');

        $byLocation('inventory_stock_movements', 'Stock movements');
        $byLocation('inventory_stock_balances', 'Stock balances');
        $byLocation('inventory_item_location_thresholds', 'Stock thresholds');

        $byBranch('menu_item_branches', 'Menu placements');
        $byBranch('recruitment_links', 'Joining links (and their submissions)');
        $byBranch('branch_delivery_settings', 'Delivery settings');
        $byBranch('branch_operating_hours', 'Operating hours');
        $byBranch('branch_order_types', 'Order types');
        $byBranch('branch_payment_methods', 'Payment methods');

        // Last, and the only one that touches people: the pivot, never the row
        // behind it.
        $byBranch('employee_branch', 'Staff assignments (unlinked, accounts kept)');

        return array_values(array_filter($plan, fn ($step) => $step['count'] > 0));
    }

    /** @param  array<int, array{label: string, count: int}>  $plan */
    private function reportPlan(array $plan): void
    {
        $this->newLine();

        if ($plan === []) {
            return;
        }

        $this->line('  <options=bold>Will be deleted</>');

        foreach ($plan as $step) {
            $this->line(sprintf('    %-46s %s', $step['label'], number_format($step['count'])));
        }

        $this->newLine();
        $this->line(sprintf('    %-46s %s', 'TOTAL ROWS', number_format(array_sum(array_column($plan, 'count')))));
    }

    /**
     * The reassuring half. Anyone authorising a purge needs to see what it does
     * not touch as plainly as what it does.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $orderIds
     */
    private function reportWhatSurvives(int $branchId, $orderIds): void
    {
        $employeeIds = DB::table('employee_branch')->where('branch_id', $branchId)->pluck('employee_id');

        $this->newLine();
        $this->line('  <options=bold>Will be kept</>');
        $this->line(sprintf('    %-46s %s', 'Staff accounts (unlinked only)', $employeeIds->count()));

        if ($employeeIds->isNotEmpty()) {
            $elsewhere = DB::table('orders')
                ->whereIn('assigned_employee_id', $employeeIds)
                ->where('branch_id', '!=', $branchId)
                ->count();

            $this->line(sprintf('    %-46s %s', '  their orders at other branches, untouched', number_format($elsewhere)));
        }

        if ($orderIds->isNotEmpty()) {
            $customers = DB::table('orders')->whereIn('id', $orderIds)
                ->whereNotNull('customer_id')->distinct()->count('customer_id');

            $this->line(sprintf('    %-46s %s', 'Customer records', number_format($customers)));

            $revenue = DB::table('orders')->whereIn('id', $orderIds)
                ->where('status', '!=', 'cancelled')->sum('total_amount');

            $this->newLine();
            $this->warn('  GHS '.number_format((float) $revenue, 2).' of recorded revenue leaves the books with these orders.');
        }
    }

    private function confirmByName(Branch $branch): bool
    {
        $this->newLine();
        $this->warn('  This cannot be undone. Restore would mean reloading a database backup.');

        $typed = $this->ask("  Type the branch name (\"{$branch->name}\") to proceed");

        return is_string($typed) && trim($typed) === $branch->name;
    }

    /**
     * @param  array<int, array{label: string, count: int, delete: callable}>  $plan
     * @param  \Illuminate\Support\Collection<int, int>  $locationIds
     */
    private function runPurge(array $plan, Branch $branch, $locationIds): int
    {
        return DB::transaction(function () use ($plan, $branch, $locationIds) {
            $deleted = 0;

            foreach ($plan as $step) {
                $deleted += (int) ($step['delete'])();
                $this->line("    · {$step['label']}");
            }

            // Emptied, not dropped: seventeen tables reference a location and
            // most restrict deletion, so dropping it would mean shredding
            // purchase orders and reconciliations that are an audit trail.
            if ($locationIds->isNotEmpty()) {
                DB::table('inventory_locations')->whereIn('id', $locationIds)->update(['is_active' => false]);
            }

            $branch->forceFill(['is_active' => false])->save();

            return $deleted;
        });
    }

    /**
     * Schema::hasTable rather than `to_regclass`, which is Postgres-only and
     * made every one of these tests error on the SQLite suite.
     */
    private function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
