<?php

use App\Domain\Inventory\Batches\BatchService;
use App\Domain\Inventory\Purchases\PurchaseService;
use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Supplier;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->batches = app(BatchService::class);
    $this->warehouse = Location::factory()->warehouse()->create();
    $this->tracked = Item::factory()->create(['expiry_tracked' => true]);
});

function fefoBalance(int $itemId, int $locationId): float
{
    $row = DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->first();

    return $row ? (float) $row->quantity : 0.0;
}

function makeBatch(int $itemId, int $locationId, float $qty, string $expiry): Batch
{
    return Batch::create([
        'item_id' => $itemId,
        'location_id' => $locationId,
        'received_qty' => $qty,
        'remaining_qty' => $qty,
        'unit_cost' => 5,
        'expiry_date' => $expiry,
        'received_at' => now(),
    ]);
}

it('allocates soonest-expiry batch first (FEFO)', function () {
    $b1 = makeBatch($this->tracked->id, $this->warehouse->id, 10, now()->addDays(5)->toDateString());
    $b2 = makeBatch($this->tracked->id, $this->warehouse->id, 10, now()->addDays(30)->toDateString());

    $alloc = $this->batches->allocate($this->tracked->id, $this->warehouse->id, 15);

    expect($alloc)->toHaveCount(2)
        ->and($alloc[0]['batch_id'])->toBe($b1->id)
        ->and($alloc[0]['qty'])->toBe(10.0)
        ->and($alloc[1]['batch_id'])->toBe($b2->id)
        ->and($alloc[1]['qty'])->toBe(5.0);

    expect((float) $b1->fresh()->remaining_qty)->toBe(0.0)
        ->and((float) $b2->fresh()->remaining_qty)->toBe(5.0);
});

it('allocates a null-batch remainder when batches are insufficient', function () {
    makeBatch($this->tracked->id, $this->warehouse->id, 10, now()->addDays(5)->toDateString());

    $alloc = $this->batches->allocate($this->tracked->id, $this->warehouse->id, 15);

    expect($alloc)->toHaveCount(2)
        ->and($alloc[1]['batch_id'])->toBeNull()
        ->and($alloc[1]['qty'])->toBe(5.0);
});

it('returns a single null-batch entry for untracked items', function () {
    $untracked = Item::factory()->create(['expiry_tracked' => false]);

    $alloc = $this->batches->allocate($untracked->id, $this->warehouse->id, 8);

    expect($alloc)->toHaveCount(1)
        ->and($alloc[0]['batch_id'])->toBeNull()
        ->and($alloc[0]['qty'])->toBe(8.0);
});

it('creates a batch when an expiry-tracked item is received', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();

    app(PurchaseService::class)->receive([
        'supplier_id' => $supplier->id,
        'destination_location_id' => $this->warehouse->id,
        'is_urgent_buy' => true,
        'urgent_buy_reason' => 'stock-up',
        'received_at' => now()->toIso8601String(),
        'items' => [[
            'item_id' => $this->tracked->id,
            'received_qty' => 20,
            'unit_cost_paid' => 4.5,
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]],
    ], $user);

    $batch = Batch::where('item_id', $this->tracked->id)->first();
    expect($batch)->not->toBeNull()
        ->and((float) $batch->remaining_qty)->toBe(20.0)
        ->and($batch->expiry_date->toDateString())->toBe(now()->addDays(10)->toDateString());
});

it('a sale consumes batches FEFO across multiple lots and reverses cleanly', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $purchaseService = app(PurchaseService::class);

    // Receipt 1: 1 unit expiring soon; Receipt 2: 100 units expiring later.
    $receive = fn (float $qty, int $days, float $cost) => $purchaseService->receive([
        'supplier_id' => $supplier->id,
        'destination_location_id' => $this->warehouse->id,
        'is_urgent_buy' => true,
        'urgent_buy_reason' => 'seed',
        'received_at' => now()->toIso8601String(),
        'items' => [['item_id' => $this->tracked->id, 'received_qty' => $qty, 'unit_cost_paid' => $cost, 'expiry_date' => now()->addDays($days)->toDateString()]],
    ], $user);
    $receive(1, 3, 5);
    $receive(100, 60, 6);

    expect(fefoBalance($this->tracked->id, $this->warehouse->id))->toBe(101.0);

    // Recipe consuming 2 units of the item per portion.
    $option = MenuItemOption::factory()->create();
    $recipe = Recipe::create([
        'menu_item_option_id' => $option->id, 'branch_id' => null,
        'is_default' => true, 'status' => 'locked', 'version' => 1, 'yield_qty' => 1,
    ]);
    $recipe->ingredients()->create(['item_id' => $this->tracked->id, 'unit_id' => $this->tracked->base_unit_id, 'quantity' => 2]);

    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id, 'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id, 'quantity' => 1,
    ]);

    app(RecipeDeductionService::class)->deductForOrder($order);

    $sorted = Batch::where('item_id', $this->tracked->id)->orderBy('expiry_date')->get();
    $soon = $sorted[0];  // expires in 3 days
    $later = $sorted[1]; // expires in 60 days

    // FEFO: drained the soonest batch (1) then 1 from the later batch.
    expect((float) $soon->fresh()->remaining_qty)->toBe(0.0)
        ->and((float) $later->fresh()->remaining_qty)->toBe(99.0)
        ->and(fefoBalance($this->tracked->id, $this->warehouse->id))->toBe(99.0)
        ->and(StockMovement::where('movement_type', 'sale')->count())->toBe(2);

    // Reversal restores batch quantities and balance.
    app(RecipeDeductionService::class)->reverseForOrder($order);
    expect((float) $soon->fresh()->remaining_qty)->toBe(1.0)
        ->and((float) $later->fresh()->remaining_qty)->toBe(100.0)
        ->and(fefoBalance($this->tracked->id, $this->warehouse->id))->toBe(101.0);
});
