<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Models\Branch;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Unit;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use Database\Seeders\AshaimanRecipeSeeder;
use Database\Seeders\AshaimanTestStockSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The seeders are keyed on production option ids, so running them anywhere else
 * writes nothing - which is the point of the guard, but also means the write
 * path never executes outside prod. These tests build a menu that looks like the
 * one the seeder expects and exercise it properly.
 */

/** Every item option 4 ("Fried Rice / plain") consumes. */
const FRIED_RICE_PLAIN_ITEMS = [
    'Parboiled Rice' => 'kg',
    'Frying Oil (Bulk)' => 'L',
    'Onions' => 'kg',
    'Carrots' => 'kg',
    'Green Peas' => 'kg',
    'Spring Onions' => 'kg',
    'Eggs' => 'crate',
    'Seasoning Cubes' => 'pc',
    'Salt' => 'kg',
    'Black Pepper (Ground)' => 'oz',
    'Takeaway Pack (Large)' => 'pc',
    'Carrier Bags' => 'pc',
    'Disposable Cutlery Set' => 'pc',
    'Serviettes' => 'pc',
];

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);

    $this->units = collect(array_unique(array_values(FRIED_RICE_PLAIN_ITEMS)))
        ->mapWithKeys(fn ($symbol) => [$symbol => Unit::factory()->create(['symbol' => $symbol])->id]);

    foreach (FRIED_RICE_PLAIN_ITEMS as $name => $symbol) {
        Item::factory()->create(['name' => $name, 'base_unit_id' => $this->units[$symbol]]);
    }
});

/** Option 4 as production has it: menu item "Fried Rice", option_key "plain". */
function ashaimanOption(int $branchId, int $optionId, string $itemName, string $key): MenuItemOption
{
    $item = MenuItem::factory()->create(['branch_id' => $branchId, 'name' => $itemName]);

    // The factory auto-creates a "standard" option, which takes the next
    // auto-increment id and would collide with a low explicit id below.
    $item->options()->forceDelete();

    return MenuItemOption::factory()->create([
        'id' => $optionId,
        'menu_item_id' => $item->id,
        'option_key' => $key,
        'is_available' => true,
    ]);
}

function ashaimanOnHand(int $itemId, int $locationId): float
{
    $row = DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->first();

    return $row ? (float) $row->quantity : 0.0;
}

it('writes a global recipe for an option whose label still matches production', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');

    (new AshaimanRecipeSeeder)->run();

    $recipe = Recipe::where('menu_item_option_id', 4)->first();

    expect($recipe)->not->toBeNull()
        ->and($recipe->branch_id)->toBeNull()          // global: menu_items already scopes it
        ->and($recipe->is_default)->toBeTrue()
        ->and((float) $recipe->yield_qty)->toBe(1.0)
        ->and($recipe->ingredients)->toHaveCount(count(FRIED_RICE_PLAIN_ITEMS));
});

it('states every quantity in the item base unit, because deduction does not convert', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');

    (new AshaimanRecipeSeeder)->run();

    $rice = Item::where('name', 'Parboiled Rice')->first();
    $line = Recipe::where('menu_item_option_id', 4)->first()
        ->ingredients()->where('item_id', $rice->id)->first();

    expect((float) $line->quantity)->toBe(0.25)
        ->and($line->unit_id)->toBe($rice->base_unit_id);
});

it('matches a dish whose own name contains the separator', function () {
    // A third of this menu is named like this. Comparing by splitting the
    // expected label on its first " / " tears the name in half and refuses the
    // dish as drifted - which is exactly what happened on the first prod run.
    ashaimanOption($this->branch->id, 16, 'Fried Rice / Jollof Rice / Noodles + 3 Drumsticks', 'fried-rice');

    (new AshaimanRecipeSeeder)->run();

    expect(Recipe::where('menu_item_option_id', 16)->exists())->toBeTrue();
});

it('refuses to attach a recipe to an option that has drifted to another dish', function () {
    // Option 4 exists, but it is no longer "Fried Rice / plain".
    ashaimanOption($this->branch->id, 4, 'Goat Light Soup', 'plain');

    (new AshaimanRecipeSeeder)->run();

    expect(Recipe::where('menu_item_option_id', 4)->exists())->toBeFalse();
});

it('converges rather than duplicating when run twice', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanRecipeSeeder)->run();

    expect(Recipe::where('menu_item_option_id', 4)->count())->toBe(1)
        ->and(Recipe::where('menu_item_option_id', 4)->first()->ingredients)
        ->toHaveCount(count(FRIED_RICE_PLAIN_ITEMS));
});

it('revives a soft-deleted recipe instead of reporting a phantom write', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');

    (new AshaimanRecipeSeeder)->run();
    Recipe::where('menu_item_option_id', 4)->first()->delete();

    (new AshaimanRecipeSeeder)->run();

    // The unique index is on (option, branch) and ignores soft deletes, so a
    // seeder that could not clear deleted_at would report success and leave the
    // option deducting nothing.
    expect(Recipe::where('menu_item_option_id', 4)->exists())->toBeTrue()
        ->and(Recipe::withTrashed()->where('menu_item_option_id', 4)->count())->toBe(1);
});

it('tops the branch up to a day of cover using count adjustments, not purchases', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    $rice = Item::where('name', 'Parboiled Rice')->first();

    // 0.25 per plate x 3 covers x 1.2 headroom = 0.9, already at 1 dp.
    expect(ashaimanOnHand($rice->id, $location->id))->toBe(0.9)
        ->and(StockMovement::where('location_id', $location->id)->pluck('movement_type')->unique()->all())
        ->toBe(['count_adjustment']);
});

it('rounds discrete units up to whole ones', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    // Eggs: 0.0333 crate x 3 x 1.2 = 0.12 of a crate, but you hold whole crates.
    $eggs = Item::where('name', 'Eggs')->first();
    expect(ashaimanOnHand($eggs->id, $location->id))->toBe(1.0);
});

it('never writes off stock the branch already counted', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);
    $rice = Item::where('name', 'Parboiled Rice')->first();

    $this->engine->post([
        'item_id' => $rice->id,
        'location_id' => $location->id,
        'quantity' => 40,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 18.0,
        'idempotency_key' => 'real-stock-already-there',
    ]);

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    expect(ashaimanOnHand($rice->id, $location->id))->toBe(40.0);
});

it('trues a negative balance up to zero so the day does not start impossible', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);

    // An item no recipe touches, left negative by an earlier over-deduction.
    $stray = Item::factory()->create(['name' => 'Vegetable Oil', 'base_unit_id' => $this->units['L']]);
    $this->engine->post([
        'item_id' => $stray->id,
        'location_id' => $location->id,
        'quantity' => -2,
        'movement_type' => 'sale',
        'idempotency_key' => 'drove-it-negative',
    ]);

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    expect(ashaimanOnHand($stray->id, $location->id))->toBe(0.0);
});

it('tops up the difference when the recipes grew since the last run today', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);
    $rice = Item::where('name', 'Parboiled Rice')->first();

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();
    expect(ashaimanOnHand($rice->id, $location->id))->toBe(0.9);

    // A second dish is recipe'd later the same day - as happens when a first
    // run only covered part of the menu. Demand doubles, and the second stock
    // run must make up the difference rather than recognising a stale key.
    ashaimanOption($this->branch->id, 1, 'Jollof Rice', 'plain');
    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    // Two dishes at 0.25 each x 3 covers x 1.2 = 1.8
    expect(ashaimanOnHand($rice->id, $location->id))->toBe(1.8);
});

it('does not refill what the day already sold when it runs again mid-service', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);
    $rice = Item::where('name', 'Parboiled Rice')->first();

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    // Trading draws the branch down.
    $this->engine->post([
        'item_id' => $rice->id,
        'location_id' => $location->id,
        'quantity' => -0.6,
        'movement_type' => 'sale',
        'idempotency_key' => 'lunchtime-trade',
    ]);

    // A deploy at lunchtime re-runs the seeder. It must leave the depletion
    // alone - refilling here would erase the evidence the whole exercise is
    // meant to produce.
    (new AshaimanTestStockSeeder)->run();

    expect(ashaimanOnHand($rice->id, $location->id))->toBe(0.3);
});

it('is idempotent on the same day but tops up again on the next', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    $location = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);
    $rice = Item::where('name', 'Parboiled Rice')->first();

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    expect(ashaimanOnHand($rice->id, $location->id))->toBe(0.9);

    // A day's trading drains it, and tomorrow's run refills to the same target.
    $this->engine->post([
        'item_id' => $rice->id,
        'location_id' => $location->id,
        'quantity' => -0.9,
        'movement_type' => 'sale',
        'idempotency_key' => 'sold-through',
    ]);

    $this->travel(1)->days();
    (new AshaimanTestStockSeeder)->run();

    expect(ashaimanOnHand($rice->id, $location->id))->toBe(0.9);
});

it('stocks the same location the deduction service will resolve', function () {
    ashaimanOption($this->branch->id, 4, 'Fried Rice', 'plain');
    Location::factory()->warehouse()->create();
    $branchLocation = Location::factory()->satellite()->create(['branch_id' => $this->branch->id]);

    (new AshaimanRecipeSeeder)->run();
    (new AshaimanTestStockSeeder)->run();

    // Nothing may land in the warehouse - that fallback is the bug this whole
    // exercise is meant to prove is behind us.
    expect(StockMovement::where('location_id', '!=', $branchLocation->id)->count())->toBe(0);
});
