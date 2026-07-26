<?php

namespace Database\Seeders;

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A realistic ingredient catalogue, deduced from the live menu, with stock
 * levels spread deliberately across every band so the portal can be exercised
 * properly.
 *
 * Where the items come from: every dish on the menu was broken down to what it
 * consumes — the rice dishes to grain, oil, aromatics and seasoning; the
 * chicken lines to drumsticks, whole birds, coating flour and crumb; banku to
 * corn and cassava dough; the tilapia lines to whole fish; the wraps to
 * tortillas, beef and salad; plus the drinks, which are resale stock rather
 * than ingredients, and the packaging every order leaves in.
 *
 * Stock is spread on purpose: `abundant` well above reorder, `ok` comfortable,
 * `low` under the reorder level, `critical` under the minimum, `empty` at zero
 * — and the branch is generally leaner than the mother kitchen, which is what
 * makes a requisition worth raising.
 *
 * Idempotent. Items are matched by name, and opening stock is posted through
 * the ledger with a stable idempotency key, so re-running changes nothing.
 */
class InventoryMenuCatalogSeeder extends Seeder
{
    /** Fraction of the reorder level used as the critical minimum. */
    private const MIN_RATIO = 0.4;

    /**
     * name, unit symbol, category, storage, reorder level, unit cost (GHS),
     * warehouse state, branch state.
     *
     * @var array<int, array{0:string,1:string,2:string,3:string,4:float,5:float,6:string,7:string}>
     */
    private const ITEMS = [
        // ── Proteins — drumsticks, rotisserie, tilapia, wraps, seafood ───────
        ['Chicken Drumsticks', 'kg', 'Proteins', 'frozen', 80, 42.0, 'abundant', 'low'],
        ['Whole Chicken (Rotisserie)', 'pc', 'Proteins', 'frozen', 40, 65.0, 'ok', 'empty'],
        ['Tilapia (Whole, Large)', 'pc', 'Proteins', 'frozen', 60, 55.0, 'low', 'critical'],
        ['Beef (Diced)', 'kg', 'Proteins', 'frozen', 30, 78.0, 'ok', 'empty'],
        ['Shrimp (Peeled)', 'kg', 'Proteins', 'frozen', 20, 120.0, 'critical', 'empty'],
        ['Squid Rings', 'kg', 'Proteins', 'frozen', 15, 95.0, 'empty', 'empty'],

        // ── Grains & starch — rice, banku, noodles, coating, wraps ───────────
        ['Long Grain Rice', 'kg', 'Grains & Starch', 'dry', 120, 18.0, 'abundant', 'ok'],
        ['Corn Dough (Banku)', 'kg', 'Grains & Starch', 'cold', 50, 12.0, 'ok', 'low'],
        ['Cassava Dough', 'kg', 'Grains & Starch', 'cold', 40, 11.0, 'low', 'empty'],
        ['Instant Noodles', 'pc', 'Grains & Starch', 'dry', 200, 4.5, 'abundant', 'ok'],
        ['All-Purpose Flour', 'kg', 'Grains & Starch', 'dry', 40, 15.0, 'ok', 'empty'],
        ['Breadcrumbs', 'kg', 'Grains & Starch', 'dry', 20, 22.0, 'low', 'empty'],
        ['Tortilla Wraps', 'pc', 'Grains & Starch', 'dry', 150, 3.2, 'ok', 'low'],

        // ── Oil & fats ───────────────────────────────────────────────────────
        ['Frying Oil (Bulk)', 'L', 'Oil & Fats', 'dry', 150, 26.0, 'abundant', 'ok'],
        ['Butter', 'kg', 'Oil & Fats', 'cold', 15, 68.0, 'low', 'empty'],

        // ── Spice & herbs ────────────────────────────────────────────────────
        ['Seasoning Cubes', 'pc', 'Spice & Herbs', 'ambient', 500, 0.6, 'abundant', 'ok'],
        ['Salt', 'kg', 'Spice & Herbs', 'ambient', 25, 4.0, 'ok', 'ok'],
        ['Black Pepper (Ground)', 'oz', 'Spice & Herbs', 'ambient', 30, 9.0, 'low', 'empty'],
        ['Thyme (Dried)', 'oz', 'Spice & Herbs', 'ambient', 20, 7.5, 'critical', 'empty'],
        ['Bay Leaves', 'oz', 'Spice & Herbs', 'ambient', 10, 6.0, 'empty', 'empty'],
        ['Ginger (Fresh)', 'kg', 'Spice & Herbs', 'cold', 20, 16.0, 'ok', 'low'],
        ['Garlic (Fresh)', 'kg', 'Spice & Herbs', 'cold', 20, 24.0, 'low', 'critical'],
        ['Scotch Bonnet Pepper', 'kg', 'Spice & Herbs', 'cold', 25, 30.0, 'ok', 'critical'],
        ['Shito (Pepper Sauce)', 'L', 'Spice & Herbs', 'ambient', 30, 45.0, 'ok', 'low'],

        // ── Vegetables ───────────────────────────────────────────────────────
        ['Onions', 'kg', 'Vegetables', 'dry', 80, 14.0, 'abundant', 'ok'],
        ['Tomatoes (Fresh)', 'kg', 'Vegetables', 'cold', 60, 17.0, 'low', 'critical'],
        ['Tomato Paste', 'kg', 'Vegetables', 'ambient', 40, 21.0, 'ok', 'ok'],
        ['Green Bell Pepper', 'kg', 'Vegetables', 'cold', 30, 19.0, 'ok', 'empty'],
        ['Carrots', 'kg', 'Vegetables', 'cold', 40, 12.0, 'low', 'low'],
        ['Green Peas', 'kg', 'Vegetables', 'frozen', 25, 20.0, 'ok', 'empty'],
        ['Spring Onions', 'kg', 'Vegetables', 'cold', 15, 18.0, 'critical', 'empty'],
        ['Cabbage', 'kg', 'Vegetables', 'cold', 30, 9.0, 'ok', 'low'],
        ['Lettuce', 'kg', 'Vegetables', 'cold', 20, 15.0, 'critical', 'empty'],
        ['Cucumber', 'kg', 'Vegetables', 'cold', 15, 11.0, 'low', 'empty'],
        ['Ripe Plantain', 'pc', 'Vegetables', 'ambient', 100, 2.5, 'abundant', 'low'],

        // ── Prepared food ────────────────────────────────────────────────────
        ['Kɔkɔɔ Sauce', 'L', 'Prepared Food', 'cold', 25, 38.0, 'ok', 'low'],

        // ── Beverages — resale stock, not ingredients ────────────────────────
        ['Coca Cola 450ml', 'crate', 'Beverages', 'dry', 30, 96.0, 'ok', 'low'],
        ['Fanta 330ml', 'crate', 'Beverages', 'dry', 25, 88.0, 'low', 'empty'],
        ['Can Malt', 'crate', 'Beverages', 'dry', 20, 130.0, 'ok', 'ok'],
        ['Ceres Juice (Red Grape)', 'pc', 'Beverages', 'dry', 60, 18.0, 'abundant', 'ok'],
        ['Ceres Juice (Mixed)', 'pc', 'Beverages', 'dry', 60, 18.0, 'ok', 'low'],
        ['Don Simon Juice (Large)', 'pc', 'Beverages', 'dry', 40, 22.0, 'low', 'empty'],
        ['Don Simon Juice (Small)', 'pc', 'Beverages', 'dry', 40, 12.0, 'ok', 'empty'],
        ['Frutelli Cocktail', 'pc', 'Beverages', 'dry', 50, 14.0, 'critical', 'empty'],
        ['Bel Aqua Water 500ml', 'crate', 'Beverages', 'dry', 40, 24.0, 'abundant', 'ok'],

        // ── Packaging — every order leaves in some of this ───────────────────
        ['Takeaway Pack (Large)', 'pc', 'Packaging', 'dry', 500, 1.8, 'abundant', 'ok'],
        ['Takeaway Pack (Medium)', 'pc', 'Packaging', 'dry', 500, 1.3, 'ok', 'low'],
        ['Carrier Bags', 'pc', 'Packaging', 'dry', 1000, 0.4, 'abundant', 'ok'],
        ['Disposable Cutlery Set', 'pc', 'Packaging', 'dry', 800, 0.9, 'low', 'critical'],
        ['Serviettes', 'pc', 'Packaging', 'dry', 600, 0.3, 'ok', 'empty'],
        ['Foil Wrap', 'pc', 'Packaging', 'dry', 100, 8.0, 'critical', 'empty'],
    ];

    public function run(): void
    {
        $engine = app(MovementPostingEngine::class);

        $units = Unit::pluck('id', 'symbol');
        $warehouse = Location::where('type', 'warehouse')->orderBy('id')->first();
        $satellite = Location::where('type', 'satellite')->whereNotNull('branch_id')->orderBy('id')->first();

        if (! $warehouse) {
            $this->command?->warn('No warehouse location — nothing seeded.');

            return;
        }

        foreach (['Beverages', 'Packaging'] as $name) {
            Category::firstOrCreate(
                ['name' => $name],
                ['slug' => \Illuminate\Support\Str::slug($name), 'is_active' => true],
            );
        }
        $categories = Category::pluck('id', 'name');

        $created = 0;
        $stocked = 0;

        foreach (self::ITEMS as [$name, $symbol, $category, $storage, $reorder, $cost, $whState, $brState]) {
            $unitId = $units[$symbol] ?? null;
            if (! $unitId) {
                $this->command?->warn("Skipped {$name}: no unit '{$symbol}'.");

                continue;
            }

            $item = Item::firstOrNew(['name' => $name]);

            if (! $item->exists) {
                $item->sku = $this->nextSku();
                $created++;
            }

            $item->fill([
                'category_id' => $categories[$category] ?? null,
                'base_unit_id' => $unitId,
                'storage_type' => $storage,
                'reorder_level' => $reorder,
                // Below reorder, not above it — the bands only read correctly
                // when the critical minimum sits under the reorder trigger.
                'min_threshold' => round($reorder * self::MIN_RATIO, 2),
                'is_active' => true,
                'is_consumable' => true,
            ]);
            $item->save();

            foreach ([[$warehouse, $whState], [$satellite, $brState]] as [$location, $state]) {
                if (! $location) {
                    continue;
                }

                $qty = $this->quantityFor($state, (float) $reorder);
                if ($qty <= 0) {
                    continue; // `empty` means leave it at zero
                }

                $engine->post([
                    'item_id' => $item->id,
                    'location_id' => $location->id,
                    'quantity' => $qty,
                    'movement_type' => 'purchase',
                    'unit_cost_at_time' => $cost,
                    // Stable, so re-running the seeder does not double the stock.
                    'idempotency_key' => "menu-catalog-seed:{$item->id}:{$location->id}",
                ]);
                $stocked++;
            }
        }

        $this->command?->info("Menu catalogue: {$created} new item(s), {$stocked} opening-stock posting(s).");
    }

    /** Where a state sits relative to the item's own thresholds. */
    private function quantityFor(string $state, float $reorder): float
    {
        $min = $reorder * self::MIN_RATIO;

        return match ($state) {
            'abundant' => round($reorder * 4, 2),
            'ok' => round($reorder * 1.6, 2),
            'low' => round(($reorder + $min) / 2, 2),   // under reorder, over min
            'critical' => round($min * 0.5, 2),          // under min
            default => 0.0,                              // empty
        };
    }

    /** Mirrors the sequential ITM-000001 scheme the API assigns. */
    private function nextSku(): string
    {
        $max = (int) DB::table('inventory_items')
            ->where('sku', 'like', 'ITM-%')
            ->selectRaw("MAX(CAST(SUBSTRING(sku, 5) AS INTEGER)) as m")
            ->value('m');

        return 'ITM-'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
}
