<?php

namespace Database\Seeders;

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Inventory\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Brings the Ashaiman branch up to roughly one trading day of cover, so that a
 * real day's sales deduct against stock that is actually there.
 *
 * Demand is DERIVED from the seeded recipes rather than restated here, so the
 * two files cannot drift apart. Run AshaimanRecipeSeeder first; with no recipes
 * there is no demand and this seeder correctly does nothing.
 *
 * Posted as `count_adjustment`, deliberately, not `purchase`. Nothing was bought.
 * A count adjustment says "we counted, and this is what is on the shelf", which
 * is the only honest thing to call an opening figure somebody decided on. A fake
 * purchase would inflate cost-of-goods on a real ledger and make the day's
 * margin a lie.
 *
 * It only ever ADDS. Where the branch already holds more than a day's cover it
 * is left alone - this seeder is not entitled to write off stock somebody
 * counted for real.
 *
 * Negative balances are trued up to zero as a side effect, on the grounds that
 * no test should start from a position the warehouse would call impossible.
 *
 * The idempotency key carries the date: re-running today changes nothing,
 * running again tomorrow tops the branch back up.
 */
class AshaimanTestStockSeeder extends Seeder
{
    private const BRANCH = 'Ashaiman';

    /**
     * Units of EACH option assumed to sell in a day. The whole sizing model is
     * this one number - raise it for a fatter buffer, lower it to make the
     * branch run out sooner and on purpose.
     */
    private const COVERS_PER_OPTION = 3;

    /** Slack on top of computed demand, so a normal day does not go negative. */
    private const HEADROOM = 1.2;

    /** Units counted in whole numbers - you cannot hold 2.4 takeaway packs. */
    private const DISCRETE_UNITS = ['pc', 'crate', 't'];

    public function run(): void
    {
        $branchId = DB::table('branches')->where('name', self::BRANCH)->value('id');
        if (! $branchId) {
            $this->command?->error('No "'.self::BRANCH.'" branch - nothing seeded.');

            return;
        }

        // Resolved exactly as RecipeDeductionService::resolveDeductionLocation
        // does, so we stock the location the sales will actually hit.
        $location = Location::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $location) {
            $this->command?->error('No active inventory location for '.self::BRANCH.' - sales would fall back to the warehouse. Nothing seeded.');

            return;
        }

        $demand = $this->demandForOneDay($branchId);

        if ($demand === []) {
            $this->command?->warn('No recipes resolve for the '.self::BRANCH.' menu - run AshaimanRecipeSeeder first. Nothing seeded.');

            return;
        }

        $units = DB::table('inventory_items as i')
            ->leftJoin('inventory_units as u', 'u.id', '=', 'i.base_unit_id')
            ->pluck('u.symbol', 'i.id');

        $balances = DB::table('inventory_stock_balances')
            ->where('location_id', $location->id)
            ->pluck('quantity', 'item_id');

        $names = DB::table('inventory_items')->pluck('name', 'id');

        // Anything the day needs, plus anything sitting below zero today.
        $itemIds = collect(array_keys($demand))
            ->merge($balances->filter(fn ($q) => (float) $q < 0)->keys())
            ->unique();

        $engine = app(MovementPostingEngine::class);
        $stamp = now()->format('Ymd');

        $posted = 0;
        $skipped = 0;
        $rows = [];

        foreach ($itemIds as $itemId) {
            $itemId = (int) $itemId;
            $current = (float) ($balances[$itemId] ?? 0);
            $target = $this->roundUpForUnit(
                ($demand[$itemId] ?? 0) * self::HEADROOM,
                $units[$itemId] ?? null,
            );

            $delta = round($target - $current, 4);

            if ($delta <= 0) {
                $skipped++;

                continue;
            }

            $engine->post([
                'item_id' => $itemId,
                'location_id' => $location->id,
                'quantity' => $delta,
                'movement_type' => 'count_adjustment',
                // A count does not reprice stock, so no cost is asserted; the
                // weighted average is left exactly where it was.
                'unit_cost_at_time' => null,
                'idempotency_key' => "ashaiman-test-stock:{$stamp}:{$itemId}:{$location->id}",
            ]);

            $rows[] = sprintf(
                '  %-32s %8.2f -> %8.2f  (%+.2f %s)',
                mb_strimwidth((string) ($names[$itemId] ?? $itemId), 0, 32),
                $current,
                $target,
                $delta,
                $units[$itemId] ?? '',
            );
            $posted++;
        }

        $this->command?->info(sprintf(
            '%s (location %d): %d item(s) topped up, %d already covered. Sized for %d of every option, +%d%% headroom.',
            $location->name,
            $location->id,
            $posted,
            $skipped,
            self::COVERS_PER_OPTION,
            (int) round((self::HEADROOM - 1) * 100),
        ));

        foreach ($rows as $row) {
            $this->command?->line($row);
        }
    }

    /**
     * Ingredient demand for one day, read off the recipes that would actually
     * resolve for this branch's menu.
     *
     * @return array<int, float> item id => quantity in the item's base unit
     */
    private function demandForOneDay(int $branchId): array
    {
        $lines = DB::table('inventory_recipe_ingredients as ri')
            ->join('inventory_recipes as r', 'r.id', '=', 'ri.recipe_id')
            ->join('menu_item_options as o', 'o.id', '=', 'r.menu_item_option_id')
            ->join('menu_items as m', 'm.id', '=', 'o.menu_item_id')
            ->where('m.branch_id', $branchId)
            ->whereNull('r.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereNull('m.deleted_at')
            ->where('o.is_available', true)
            // Mirrors resolveRecipe: a branch override, or the global default.
            ->where(fn ($q) => $q->where('r.branch_id', $branchId)->orWhereNull('r.branch_id'))
            ->get(['ri.item_id', 'ri.quantity', 'r.yield_qty']);

        $demand = [];

        foreach ($lines as $line) {
            $yield = max((float) $line->yield_qty, 0.0001);
            $qty = (float) $line->quantity * self::COVERS_PER_OPTION / $yield;
            $itemId = (int) $line->item_id;
            $demand[$itemId] = round(($demand[$itemId] ?? 0) + $qty, 4);
        }

        return $demand;
    }

    /** Whole units where the unit is physically indivisible, 1 dp otherwise. */
    private function roundUpForUnit(float $qty, ?string $symbol): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        if (in_array($symbol, self::DISCRETE_UNITS, true)) {
            return (float) ceil($qty);
        }

        return round(ceil($qty * 10) / 10, 1);
    }
}
