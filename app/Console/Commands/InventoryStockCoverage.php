<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Which ingredients a branch's recipes call for but its location has never
 * tracked.
 *
 * The stock gate no longer refuses on these — an absent balance row means "never
 * counted here", not "none in the building", and refusing on it stopped a
 * half-onboarded branch from trading at all (see StockAvailabilityService). But
 * not refusing is only half an answer: an untracked ingredient still deducts
 * nothing and still leaves the branch's true position unknown. The gap has to be
 * visible somewhere, and this is where.
 *
 * Read it as an onboarding checklist. A branch is done when this prints nothing.
 */
class InventoryStockCoverage extends Command
{
    protected $signature = 'inventory:stock-coverage
                            {--branch= : Only this branch id}';

    protected $description = 'Ingredients a branch cooks with but has never counted at its location';

    public function handle(): int
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->when($this->option('branch'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($branches->isEmpty()) {
            $this->warn('No active branches matched.');

            return self::SUCCESS;
        }

        $anyGap = false;

        foreach ($branches as $branch) {
            $this->newLine();
            $this->info("#{$branch->id} {$branch->name}");

            $locationId = DB::table('inventory_locations')
                ->where('branch_id', $branch->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if (! $locationId) {
                $anyGap = true;
                $this->error('  No inventory location. Run branch:provision-locations.');

                continue;
            }

            $tracked = DB::table('inventory_stock_balances')
                ->where('location_id', $locationId)
                ->pluck('item_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            // Every ingredient reachable from a dish this branch serves.
            $needed = DB::table('inventory_recipe_ingredients as ri')
                ->join('inventory_recipes as r', 'r.id', '=', 'ri.recipe_id')
                ->join('menu_item_options as o', 'o.id', '=', 'r.menu_item_option_id')
                ->join('menu_items as m', 'm.id', '=', 'o.menu_item_id')
                ->join('menu_item_branches as mb', 'mb.menu_item_id', '=', 'm.id')
                ->join('inventory_items as i', 'i.id', '=', 'ri.item_id')
                ->where('mb.branch_id', $branch->id)
                ->whereNull('r.deleted_at')
                ->whereNull('o.deleted_at')
                ->groupBy('i.id', 'i.name')
                ->select('i.id', 'i.name', DB::raw('count(distinct o.id) as options'))
                ->orderByDesc(DB::raw('count(distinct o.id)'))
                ->get();

            $missing = $needed->reject(fn ($row) => $tracked->has((int) $row->id))->values();

            $this->line("  location #{$locationId} · {$tracked->count()} item(s) counted · {$needed->count()} needed by recipes");

            if ($missing->isEmpty()) {
                $this->line('  <fg=green>Every ingredient its recipes call for has been counted here.</>');

                continue;
            }

            $anyGap = true;
            $this->warn("  {$missing->count()} ingredient(s) never counted here:");
            $this->table(
                ['Ingredient', 'Dishes it is in'],
                $missing->map(fn ($row) => [$row->name, $row->options])->all(),
            );
        }

        if ($anyGap) {
            $this->newLine();
            $this->warn('Untracked ingredients deduct nothing and leave the branch position unknown.');
            $this->line('Count them in through a delivery or an opening count to close the gap.');
        }

        return self::SUCCESS;
    }
}
