<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Top a branch's location up to a flat floor of every ingredient its recipes
 * call for, so it can trade without the stock gate refusing.
 *
 * This posts REAL ledger movements carrying FAKE quantities. That is the whole
 * point and also the whole danger: once seeded, the branch's stock figures no
 * longer describe anything in the building, and every later report, valuation
 * and variance at that location inherits the fiction. It exists for test and
 * demo branches. Live branches count stock in through a delivery or an opening
 * count instead — hence the --force gate on anything not named like a test.
 *
 * Convergent, not idempotent: it tops each item up to the floor, so running it
 * twice is harmless and running it after a day of selling re-fills what sold.
 * Dry run unless --apply.
 */
class InventorySeedBranchStock extends Command
{
    protected $signature = 'inventory:seed-branch-stock
                            {--branch= : Branch id to seed (required)}
                            {--units=200 : Floor to bring every ingredient up to}
                            {--apply : Write the movements. Without this the command only reports.}
                            {--force : Required to seed a branch whose name does not look like a test branch}';

    protected $description = 'Seed a test branch with a flat stock floor so orders can be placed against it';

    public function handle(MovementPostingEngine $engine): int
    {
        $branchId = (int) $this->option('branch');
        $units = (float) $this->option('units');
        $apply = (bool) $this->option('apply');

        if ($branchId <= 0) {
            $this->error('--branch is required.');

            return self::FAILURE;
        }

        if ($units <= 0) {
            $this->error('--units must be greater than zero.');

            return self::FAILURE;
        }

        $branch = Branch::find($branchId);

        if (! $branch) {
            $this->error("No branch #{$branchId}.");

            return self::FAILURE;
        }

        // Fake stock on a live branch corrupts every downstream number. Make the
        // caller say out loud that they mean it.
        if (! $this->looksLikeTestBranch($branch->name) && ! $this->option('force')) {
            $this->error("\"{$branch->name}\" does not look like a test branch.");
            $this->line('Seeding writes fake quantities into the real ledger. Pass --force if you truly mean it.');

            return self::FAILURE;
        }

        $locationId = DB::table('inventory_locations')
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        if (! $locationId) {
            $this->error('  No active inventory location. Run branch:provision-locations first.');

            return self::FAILURE;
        }

        $this->info("#{$branch->id} {$branch->name} · location #{$locationId} · floor {$units}");

        $items = $this->itemsToSeed($branch->id, (int) $locationId);

        if ($items->isEmpty()) {
            $this->warn('  Nothing to seed — this branch has no menu recipes and no tracked items.');

            return self::SUCCESS;
        }

        $short = $items->filter(fn ($row) => (float) $row->current < $units)->values();

        $this->line("  {$items->count()} ingredient(s) in scope · {$short->count()} below the floor");

        if ($short->isEmpty()) {
            $this->line('  <fg=green>Every ingredient is already at or above the floor.</>');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->table(
                ['Ingredient', 'Now', 'Top-up'],
                $short->take(60)->map(fn ($row) => [
                    $row->name,
                    rtrim(rtrim(number_format((float) $row->current, 4, '.', ''), '0'), '.') ?: '0',
                    rtrim(rtrim(number_format($units - (float) $row->current, 4, '.', ''), '0'), '.'),
                ])->all(),
            );

            if ($short->count() > 60) {
                $this->line('  … '.($short->count() - 60).' more');
            }

            $this->newLine();
            $this->warn('  Dry run. Re-run with --apply to post these movements.');

            return self::SUCCESS;
        }

        // A single run id keeps every movement of this batch greppable together,
        // and keeps idempotency keys unique across runs — the top-up arithmetic,
        // not the key, is what makes a repeat run safe.
        $runId = now()->format('YmdHis');
        $posted = 0;

        $bar = $this->output->createProgressBar($short->count());
        $bar->start();

        foreach ($short as $row) {
            $topUp = round($units - (float) $row->current, 4);

            if ($topUp <= 0) {
                $bar->advance();

                continue;
            }

            $engine->post([
                'item_id' => (int) $row->id,
                'location_id' => (int) $locationId,
                'quantity' => $topUp,
                'movement_type' => 'opening_balance',
                'idempotency_key' => "seed-branch-stock:{$locationId}:{$row->id}:{$runId}",
                'occurred_at' => now(),
            ]);

            $posted++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("  Posted {$posted} opening_balance movement(s) at location #{$locationId}.");
        $this->line("  Run id {$runId} — movements are tagged with it in their idempotency_key.");
        $this->newLine();
        $this->warn('  These quantities are seeded, not counted. Do not read this location\'s');
        $this->warn('  valuation or variance as real until a genuine count replaces them.');

        return self::SUCCESS;
    }

    /**
     * Every ingredient the branch's menu can consume, plus anything already
     * tracked at its location, with the current balance attached.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function itemsToSeed(int $branchId, int $locationId): \Illuminate\Support\Collection
    {
        // Reachable from a dish this branch serves.
        $needed = DB::table('inventory_recipe_ingredients as ri')
            ->join('inventory_recipes as r', 'r.id', '=', 'ri.recipe_id')
            ->join('menu_item_options as o', 'o.id', '=', 'r.menu_item_option_id')
            ->join('menu_items as m', 'm.id', '=', 'o.menu_item_id')
            ->join('menu_item_branches as mb', 'mb.menu_item_id', '=', 'm.id')
            ->where('mb.branch_id', $branchId)
            ->whereNull('r.deleted_at')
            ->whereNull('o.deleted_at')
            ->distinct()
            ->pluck('ri.item_id');

        // Already counted here — top these up too, or a partially stocked branch
        // keeps whatever near-zero position it had.
        $tracked = DB::table('inventory_stock_balances')
            ->where('location_id', $locationId)
            ->pluck('item_id');

        $itemIds = $needed->merge($tracked)->map(fn ($id) => (int) $id)->unique()->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        return DB::table('inventory_items as i')
            ->leftJoin('inventory_stock_balances as b', function ($join) use ($locationId) {
                $join->on('b.item_id', '=', 'i.id')->where('b.location_id', '=', $locationId);
            })
            ->whereIn('i.id', $itemIds)
            ->whereNull('i.deleted_at')
            ->orderBy('i.name')
            ->select('i.id', 'i.name', DB::raw('coalesce(b.quantity, 0) as current'))
            ->get();
    }

    private function looksLikeTestBranch(string $name): bool
    {
        return (bool) preg_match('/\b(test|demo|sandbox|staging)\b/i', $name);
    }
}
