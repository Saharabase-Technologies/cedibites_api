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

/*
 * What an item is actually worth.
 *
 * There are two `weighted_avg_cost` columns. The BALANCE one is maintained by
 * MovementPostingEngine on every movement and is what WastageService,
 * TransferService and the closing all value against. The ITEM one is only ever
 * written by PurchaseService, so anything that arrived by transfer, production
 * or adjustment sits at its default of 0.
 *
 * The resource served the item column, so the wastage form priced transferred
 * goods at GHS 0.00 - and then told the user the loss was under the threshold
 * and would be written off on the spot, while the server was about to value it
 * properly and demand a return to the warehouse.
 */
it('values an item from the stock it actually holds, not the stale item column', function () {
    $branch = Location::factory()->satellite()->create();

    // Goods arriving by transfer: the balance learns the cost, the item row
    // never does. This is the exact shape of the production bug - Parboiled Rice
    // and Chicken Drumsticks both sat at item cost 0 with real balances.
    app(MovementPostingEngine::class)->post([
        'item_id' => $this->chicken->id, 'location_id' => $branch->id, 'quantity' => 10,
        'movement_type' => 'transfer_in', 'unit_cost_at_time' => 42.0,
        'idempotency_key' => 'cost-transfer-in',
    ]);

    // Poison the stale column with a figure that is not merely absent but
    // WRONG, so passing this cannot be an accident of both happening to be 0.
    $this->chicken->update(['weighted_avg_cost' => 999.0]);

    $body = $this->actingAs($this->user)
        ->getJson("/v1/inventory/items?location_id={$branch->id}")
        ->assertSuccessful()->json('data');

    $chicken = collect($body)->firstWhere('name', 'Chicken Drumsticks');
    // Cast: a whole number serialises to JSON as `42`, not `42.0`.
    expect((float) $chicken['weighted_avg_cost'])->toBe(42.0);
});

it('weights the average by quantity when several locations are in scope', function () {
    $branch = Location::factory()->satellite()->create();

    // 25 kg at 10 in the warehouse (seeded), 75 kg at 20 at the branch.
    app(MovementPostingEngine::class)->post([
        'item_id' => $this->rice->id, 'location_id' => $branch->id, 'quantity' => 75,
        'movement_type' => 'transfer_in', 'unit_cost_at_time' => 20.0,
        'idempotency_key' => 'cost-weighting',
    ]);

    $body = $this->actingAs($this->user)->getJson('/v1/inventory/items')
        ->assertSuccessful()->json('data');

    // (25x10 + 75x20) / 100 = 17.50. A plain mean would say 15.
    expect(collect($body)->firstWhere('name', 'Basmati Rice')['weighted_avg_cost'])->toBe(17.5);
});

it('falls back to the last known item cost when nothing is held anywhere', function () {
    $this->retired->update(['weighted_avg_cost' => 8.25]);

    $body = $this->actingAs($this->user)->getJson('/v1/inventory/items?is_active=0')
        ->assertSuccessful()->json('data');

    // No balances at all - a last known price beats reporting nothing.
    expect(collect($body)->firstWhere('name', 'Discontinued Sauce')['weighted_avg_cost'])->toBe(8.25);
});

it('agrees with itself between the list and the item detail', function () {
    $branch = Location::factory()->satellite()->create();
    app(MovementPostingEngine::class)->post([
        'item_id' => $this->chicken->id, 'location_id' => $branch->id, 'quantity' => 4,
        'movement_type' => 'transfer_in', 'unit_cost_at_time' => 31.5,
        'idempotency_key' => 'cost-detail-agreement',
    ]);

    $list = collect(
        $this->actingAs($this->user)->getJson("/v1/inventory/items?location_id={$branch->id}")
            ->assertSuccessful()->json('data')
    )->firstWhere('name', 'Chicken Drumsticks');

    $detail = $this->actingAs($this->user)
        ->getJson("/v1/inventory/items/{$this->chicken->id}?location_id={$branch->id}")
        ->assertSuccessful()->json('data');

    expect($detail['weighted_avg_cost'])->toBe(31.5)
        ->and($detail['weighted_avg_cost'])->toBe($list['weighted_avg_cost']);
});
