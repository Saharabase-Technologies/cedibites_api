<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Models\Inventory\Alert;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use App\Models\Inventory\StockMovement;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(RecipeDeductionService::class);
    $this->warehouse = Location::factory()->warehouse()->create();
    $this->item = Item::factory()->create();

    // Seed 100 units on hand at the warehouse.
    $this->engine->post([
        'item_id' => $this->item->id,
        'location_id' => $this->warehouse->id,
        'quantity' => 100,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'seed',
    ]);
});

function onHand(int $itemId, int $locationId): float
{
    $row = DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->first();

    return $row ? (float) $row->quantity : 0.0;
}

function makeRecipe(int $optionId, int $itemId, int $unitId, float $qty, float $yield = 1): Recipe
{
    $recipe = Recipe::create([
        'menu_item_option_id' => $optionId,
        'branch_id' => null,
        'is_default' => true,
        'status' => 'locked',
        'version' => 1,
        'yield_qty' => $yield,
    ]);
    $recipe->ingredients()->create(['item_id' => $itemId, 'unit_id' => $unitId, 'quantity' => $qty]);

    return $recipe;
}

it('deducts recipe ingredients from the warehouse when an order is paid', function () {
    $option = MenuItemOption::factory()->create();
    makeRecipe($option->id, $this->item->id, $this->item->base_unit_id, 0.5);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);

    // 100 - (0.5 per portion × 4) = 98
    expect(onHand($this->item->id, $this->warehouse->id))->toBe(98.0)
        ->and(StockMovement::where('movement_type', 'sale')->where('reference_id', $order->id)->count())->toBe(1);
});

it('is idempotent — re-firing payment does not double-deduct', function () {
    $option = MenuItemOption::factory()->create();
    makeRecipe($option->id, $this->item->id, $this->item->base_unit_id, 0.5);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);
    $this->service->deductForOrder($order); // replay

    expect(onHand($this->item->id, $this->warehouse->id))->toBe(98.0)
        ->and(StockMovement::where('movement_type', 'sale')->count())->toBe(1);
});

it('reverses the deduction on refund', function () {
    $option = MenuItemOption::factory()->create();
    makeRecipe($option->id, $this->item->id, $this->item->base_unit_id, 0.5);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);
    $this->service->reverseForOrder($order);

    expect(onHand($this->item->id, $this->warehouse->id))->toBe(100.0);
});

it('honours yield — ingredient qty is per yield portions', function () {
    $option = MenuItemOption::factory()->create();
    // recipe yields 2 portions; 1 kg per 2 portions → an order of 4 uses 2 kg
    makeRecipe($option->id, $this->item->id, $this->item->base_unit_id, 1.0, yield: 2);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);

    expect(onHand($this->item->id, $this->warehouse->id))->toBe(98.0); // 100 - (1.0 × 4/2)
});

it('allows the sale to go negative but raises a deduped negative-stock alert', function () {
    $option = MenuItemOption::factory()->create();
    // 100 on hand; 60 per portion × 4 portions = 240 demanded → balance -140.
    makeRecipe($option->id, $this->item->id, $this->item->base_unit_id, 60);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);

    // Sale completes — balance is allowed below zero.
    expect(onHand($this->item->id, $this->warehouse->id))->toBe(-140.0);

    // Exactly one open negative-stock alert is raised for this item + location.
    $alert = Alert::where('type', 'negative_stock')
        ->where('item_id', $this->item->id)
        ->where('location_id', $this->warehouse->id)
        ->where('status', 'open')
        ->get();

    expect($alert)->toHaveCount(1)
        ->and($alert->first()->severity)->toBe('critical')
        ->and($alert->first()->reference_id)->toBe($order->id);

    // A second overdrawing order updates the same alert rather than spamming rows.
    $order2 = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order2->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 1,
    ]);
    $this->service->deductForOrder($order2);

    expect(Alert::where('type', 'negative_stock')->where('status', 'open')->count())->toBe(1);
});

it('is a no-op when the ordered option has no recipe', function () {
    $option = MenuItemOption::factory()->create();

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => 4,
    ]);

    $this->service->deductForOrder($order);

    expect(onHand($this->item->id, $this->warehouse->id))->toBe(100.0)
        ->and(StockMovement::where('movement_type', 'sale')->count())->toBe(0);
});
