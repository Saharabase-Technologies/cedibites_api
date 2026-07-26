<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Enums\Permission;
use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * The items screen's search and filters.
 *
 * Search was the reported bug: production is Postgres, where `LIKE` is
 * case-SENSITIVE, so typing "basma" found nothing while "Basma" found Basmati
 * Rice. Every search box in the portal shared it.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->warehouse = Location::factory()->warehouse()->create();
    $this->user = User::factory()->create();
    $this->user->givePermissionTo([
        Permission::ViewInventoryCatalog->value,
        Permission::InventoryViewAllLocations->value,
    ]);

    $grains = Category::create(['name' => 'Grains & Starch', 'slug' => 'grains-starch', 'is_active' => true]);
    $proteins = Category::create(['name' => 'Proteins', 'slug' => 'proteins', 'is_active' => true]);

    $this->rice = Item::factory()->create([
        'name' => 'Basmati Rice', 'sku' => 'ITM-000101',
        'category_id' => $grains->id, 'storage_type' => 'dry', 'is_active' => true,
    ]);
    $this->chicken = Item::factory()->create([
        'name' => 'Chicken Drumsticks', 'sku' => 'ITM-000102',
        'category_id' => $proteins->id, 'storage_type' => 'frozen', 'is_active' => true,
    ]);
    $this->retired = Item::factory()->create([
        'name' => 'Discontinued Sauce', 'sku' => 'ITM-000103',
        'category_id' => $proteins->id, 'storage_type' => 'dry', 'is_active' => false,
    ]);

    // Only the rice is actually held.
    app(MovementPostingEngine::class)->post([
        'item_id' => $this->rice->id, 'location_id' => $this->warehouse->id, 'quantity' => 25,
        'movement_type' => 'purchase', 'unit_cost_at_time' => 10.0, 'idempotency_key' => 'filters-rice',
    ]);
});

function itemNames($test, string $query = ''): array
{
    return collect(
        $test->actingAs($test->user)->getJson("/v1/inventory/items{$query}")->assertSuccessful()->json('data')
    )->pluck('name')->all();
}

it('finds an item regardless of the case typed', function () {
    // The reported bug, exactly: lowercase prefix of a capitalised name.
    expect(itemNames($this, '?search=basma'))->toBe(['Basmati Rice'])
        ->and(itemNames($this, '?search=BASMA'))->toBe(['Basmati Rice'])
        ->and(itemNames($this, '?search=Basma'))->toBe(['Basmati Rice'])
        ->and(itemNames($this, '?search=rice'))->toBe(['Basmati Rice']);
});

it('searches the sku too, case-insensitively', function () {
    expect(itemNames($this, '?search=itm-000102'))->toBe(['Chicken Drumsticks']);
});

it('matches mid-word, not just the start', function () {
    expect(itemNames($this, '?search=drum'))->toBe(['Chicken Drumsticks']);
});

it('returns nothing for a term that matches nothing', function () {
    expect(itemNames($this, '?search=zzzznope'))->toBe([]);
});

it('filters by category', function () {
    $proteins = Category::where('name', 'Proteins')->value('id');

    expect(itemNames($this, "?category_id={$proteins}"))
        ->toBe(['Chicken Drumsticks', 'Discontinued Sauce']);
});

it('filters by storage type', function () {
    expect(itemNames($this, '?storage_type=frozen'))->toBe(['Chicken Drumsticks']);
});

it('filters by active status', function () {
    expect(itemNames($this, '?is_active=0'))->toBe(['Discontinued Sauce'])
        ->and(itemNames($this, '?is_active=1'))->toBe(['Basmati Rice', 'Chicken Drumsticks']);
});

it('narrows to only what is held when asked', function () {
    expect(itemNames($this, '?in_stock_only=1'))->toBe(['Basmati Rice'])
        // …and otherwise keeps the full catalogue, so an item you hold none of
        // is still requestable.
        ->and(itemNames($this))->toContain('Chicken Drumsticks');
});

it('combines filters rather than letting the last one win', function () {
    $grains = Category::where('name', 'Grains & Starch')->value('id');

    expect(itemNames($this, "?search=rice&category_id={$grains}&is_active=1"))->toBe(['Basmati Rice'])
        // Same search, wrong category → nothing.
        ->and(itemNames($this, '?search=rice&storage_type=frozen'))->toBe([]);
});
