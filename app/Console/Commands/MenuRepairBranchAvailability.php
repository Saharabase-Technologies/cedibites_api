<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Put back the dishes `menu:unify` marked sold out.
 *
 * The merge seeded `menu_item_branches.is_available` from
 * `menu_items.is_available`, which is a different statement:
 *
 *   menu_items.is_available          on sale company-wide — the admin's
 *   menu_item_branches.is_available  we have it here today — the branch's
 *
 * Any dish that was withdrawn company-wide when the merge ran, or whose
 * duplicate row at that branch happened to be off, got a permanent `false` on
 * the branch pivot. Putting it back on sale company-wide never cleared it —
 * nothing writes that column but the branch's own sold-out toggle, and the
 * admin's "serve here" was a no-op on a row that already existed. The branch
 * read "not available" on its whole menu with no way back.
 *
 * MenuUnify no longer writes the column at all. This clears what it left.
 *
 * A row untouched since it was written has created_at == updated_at, because
 * the merge set both in one statement. A manager's toggle goes through the
 * pivot's withTimestamps(), which moves updated_at alone. That is the whole
 * discriminator: by default this resets only rows no human has ever touched,
 * so a branch that genuinely marked something off this morning keeps its word.
 * --all overrides that and puts the entire menu back on everywhere.
 */
class MenuRepairBranchAvailability extends Command
{
    protected $signature = 'menu:repair-branch-availability
                            {--dry-run : Report what would change and write nothing}
                            {--all : Reset every sold-out flag, including ones a branch set itself}';

    protected $description = 'Clear the branch sold-out flags that menu:unify stamped from the company-wide flag';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');

        // Qualified throughout — the summary below joins `branches`, which
        // carries its own created_at/updated_at/is_available.
        $query = DB::table('menu_item_branches')->where('menu_item_branches.is_available', false);

        if (! $all) {
            // Never touched since the merge wrote it.
            $query->whereColumn('menu_item_branches.created_at', 'menu_item_branches.updated_at');
        }

        $total = DB::table('menu_item_branches')->where('is_available', false)->count();
        $target = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nothing is marked sold out. No repair needed.');

            return self::SUCCESS;
        }

        $this->line("{$total} dish/branch row(s) currently read sold out.");

        if (! $all) {
            $human = $total - $target;
            $this->line("  {$target} untouched since the merge — these will be put back on.");
            $this->line("  {$human} changed by hand since — left alone. Use --all to reset those too.");
        } else {
            $this->warn("--all: resetting every one of the {$total}, including branch-set flags.");
        }

        if ($target === 0) {
            $this->info('No rows match. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Branch', 'Dishes affected'],
            (clone $query)
                ->join('branches', 'branches.id', '=', 'menu_item_branches.branch_id')
                ->groupBy('branches.name')
                ->orderBy('branches.name')
                ->pluck(DB::raw('count(*)'), 'branches.name')
                ->map(fn ($count, $name) => [$name, $count])
                ->values()
                ->all(),
        );

        if ($dry) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        // updated_at moves, so a second run without --all will not touch these
        // again — they now look hand-changed, which from here on they are.
        $changed = $query->update(['is_available' => true, 'updated_at' => now()]);

        $this->newLine();
        $this->info("Put {$changed} dish/branch row(s) back on sale.");

        return self::SUCCESS;
    }
}
