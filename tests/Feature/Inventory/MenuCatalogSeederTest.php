<?php

use App\Models\Branch;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use Database\Seeders\InventoryMenuCatalogSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The menu-derived catalogue is meant to be run against a live database, so it
 * has to be safe to re-run and it has to actually produce the spread of stock
 * states the portal is being tested against.
 */
beforeEach(function () {
    $this->seed(Database\Seeders\InventoryUnitsSeeder::class);

    $this->warehouse = Location::factory()->warehouse()->create();
    $branch = Branch::factory()->create();
    $this->satellite = Location::factory()->satellite()->create(['branch_id' => $branch->id]);
});

function balanceOf(string $name, int $locationId): float
{
    $itemId = Item::where('name', $name)->value('id');

    return (float) (DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->value('quantity') ?? 0);
}

it('creates the catalogue with thresholds that read correctly', function () {
    (new InventoryMenuCatalogSeeder)->run();

    $rice = Item::where('name', 'Long Grain Rice')->first();

    expect(Item::count())->toBeGreaterThan(40)
        ->and($rice)->not->toBeNull()
        ->and($rice->sku)->toStartWith('ITM-')
        // The critical minimum must sit UNDER the reorder trigger, or the
        // out/critical/low/ok bands overlap and every item reads "critical".
        ->and((float) $rice->min_threshold)->toBeLessThan((float) $rice->reorder_level);

    Item::whereNotNull('reorder_level')->get()->each(function (Item $i) {
        expect((float) $i->min_threshold)->toBeLessThan((float) $i->reorder_level);
    });
});

it('spreads stock across every band, and leaves the branch leaner', function () {
    (new InventoryMenuCatalogSeeder)->run();

    $abundant = Item::where('name', 'Long Grain Rice')->first();
    $empty = Item::where('name', 'Squid Rings')->first();
    $critical = Item::where('name', 'Thyme (Dried)')->first();
    $low = Item::where('name', 'Cassava Dough')->first();

    $wh = $this->warehouse->id;

    expect(balanceOf('Long Grain Rice', $wh))->toBeGreaterThan((float) $abundant->reorder_level)
        ->and(balanceOf('Squid Rings', $wh))->toBe(0.0)
        ->and(balanceOf('Thyme (Dried)', $wh))->toBeLessThan((float) $critical->min_threshold)
        ->and(balanceOf('Cassava Dough', $wh))->toBeLessThan((float) $low->reorder_level)
        ->and(balanceOf('Cassava Dough', $wh))->toBeGreaterThan((float) $low->min_threshold);

    // The branch runs leaner than the mother kitchen — otherwise there is
    // never a reason to raise a requisition.
    expect(balanceOf('Long Grain Rice', $this->satellite->id))
        ->toBeLessThan(balanceOf('Long Grain Rice', $wh));
});

it('is safe to run twice — no duplicate items, no doubled stock', function () {
    (new InventoryMenuCatalogSeeder)->run();
    $items = Item::count();
    $rice = balanceOf('Long Grain Rice', $this->warehouse->id);

    (new InventoryMenuCatalogSeeder)->run();

    expect(Item::count())->toBe($items)
        ->and(balanceOf('Long Grain Rice', $this->warehouse->id))->toBe($rice);
});
