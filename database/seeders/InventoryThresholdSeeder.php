<?php

namespace Database\Seeders;

use App\Models\Inventory\Item;
use App\Models\Inventory\ItemLocationThreshold;
use App\Models\Inventory\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Two repairs to the reorder signal.
 *
 * ONE: items shipped with the critical minimum ABOVE the reorder level. The band
 * check tests the minimum first, so "Low" is unreachable and stock jumps
 * straight from OK to Critical - the warning that was supposed to give notice
 * never fires. Three hand-created items on production are this way round
 * (Chicken Thighs 75/150, Eggs 50/100, Mayonaise 50/75), plus Parboiled Rice,
 * which carries no thresholds at all and so can never warn about anything -
 * awkward, given it is now the main rice item every rice dish consumes.
 *
 * TWO: Ashaiman's own thresholds, derived from what the branch actually gets
 * through in a day. The item-level figures are central-warehouse scale (1000
 * carrier bags, 150 L of frying oil); measured against those, a branch holding
 * one day of cover reads Critical on 49 of 55 lines the moment it is stocked.
 * That is not a shortage, it is the wrong yardstick, and a board that is all red
 * on a normal morning cannot show you the one line that genuinely ran out.
 *
 * Demand comes from the recipes, so run this after AshaimanRecipeSeeder. With no
 * recipes there is no demand and the branch half is skipped.
 *
 * Idempotent, and it never silently overwrites a threshold somebody chose: the
 * global repair only touches rows that are actually inverted or empty.
 */
class InventoryThresholdSeeder extends Seeder
{
    private const BRANCH = 'Ashaiman';

    /** Units of each option assumed to sell in a day - matches the stock seeder. */
    private const COVERS_PER_OPTION = 3;

    /** Reorder when a day's cover is gone; critical at 40% of it. */
    private const REORDER_DAYS = 1.0;

    private const MIN_RATIO = 0.4;

    /**
     * Items whose global thresholds are inverted or missing, repaired to sane
     * warehouse-scale figures. name => [reorder_level, min_threshold]
     */
    private const GLOBAL_REPAIRS = [
        // min was 150, above the reorder level of 75.
        'Chicken Thighs (Bone-in)' => [75, 30],
        // min was 100, above the reorder level of 50.
        'Eggs' => [50, 20],
        // min was 75, above the reorder level of 50.
        'Mayonaise' => [50, 20],
        // No thresholds at all, and it is the main rice item.
        'Parboiled Rice' => [120, 48],
        'Basmati Rice' => [50, 20],
        'Curry Powder' => [50, 20],
        'Vegetable Oil' => [100, 40],
    ];

    public function run(): void
    {
        $this->repairGlobals();
        $this->seedBranchThresholds();
    }

    /** Only touches thresholds that are inverted or absent - never a chosen one. */
    private function repairGlobals(): void
    {
        $fixed = 0;

        foreach (self::GLOBAL_REPAIRS as $name => [$reorder, $min]) {
            $item = Item::where('name', $name)->first();
            if (! $item) {
                continue;
            }

            $currentReorder = $item->reorder_level !== null ? (float) $item->reorder_level : null;
            $currentMin = $item->min_threshold !== null ? (float) $item->min_threshold : null;

            $inverted = $currentReorder !== null && $currentMin !== null && $currentMin > $currentReorder;
            $absent = $currentReorder === null || $currentMin === null;

            if (! $inverted && ! $absent) {
                continue; // somebody set a sane pair; leave it alone
            }

            $item->update(['reorder_level' => $reorder, 'min_threshold' => $min]);
            $this->command?->info("Repaired thresholds on {$name}: reorder {$reorder}, min {$min}.");
            $fixed++;
        }

        $this->command?->info("Global thresholds: {$fixed} item(s) repaired.");
    }

    /** Ashaiman's own reorder points, sized from a day of recipe demand. */
    private function seedBranchThresholds(): void
    {
        $branchId = DB::table('branches')->where('name', self::BRANCH)->value('id');
        if (! $branchId) {
            return;
        }

        // The same location the deduction service resolves and the stock seeder
        // fills, or the thresholds would describe a shelf nobody sells from.
        $location = Location::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $location) {
            $this->command?->warn('No active inventory location for '.self::BRANCH.' - branch thresholds skipped.');

            return;
        }

        $demand = $this->dailyDemand($branchId);

        if ($demand === []) {
            $this->command?->warn('No recipes resolve for the '.self::BRANCH.' menu - branch thresholds skipped. Run AshaimanRecipeSeeder first.');

            return;
        }

        $written = 0;

        foreach ($demand as $itemId => $perDay) {
            $reorder = $this->round($perDay * self::REORDER_DAYS);
            $min = $this->round($perDay * self::REORDER_DAYS * self::MIN_RATIO);

            if ($reorder <= 0) {
                continue;
            }

            ItemLocationThreshold::updateOrCreate(
                ['item_id' => $itemId, 'location_id' => $location->id],
                ['reorder_level' => $reorder, 'min_threshold' => $min],
            );
            $written++;
        }

        $this->command?->info(
            "{$location->name}: {$written} location threshold(s) set from ".
            self::COVERS_PER_OPTION.' of every option per day.'
        );
    }

    /**
     * Ingredient demand for one day, read off the recipes that resolve for this
     * branch's menu. Mirrors AshaimanTestStockSeeder so the two agree.
     *
     * @return array<int, float>
     */
    private function dailyDemand(int $branchId): array
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

    /** Three decimals is what the column holds; never round a live figure to nothing. */
    private function round(float $qty): float
    {
        return $qty > 0 ? max(round($qty, 3), 0.001) : 0.0;
    }
}
