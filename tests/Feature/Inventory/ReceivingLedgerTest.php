<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\PurchaseOrders\PurchaseOrderService;
use App\Domain\Inventory\Purchases\PurchaseService;
use App\Enums\Inventory\PurchaseOrderStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->item = Item::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();
});

function balanceFor(int $itemId, int $locationId): ?object
{
    return DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)
        ->where('location_id', $locationId)
        ->first();
}

it('posts a movement and updates the balance', function () {
    $this->engine->post([
        'item_id' => $this->item->id,
        'location_id' => $this->warehouse->id,
        'quantity' => 10,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'k1',
    ]);

    $bal = balanceFor($this->item->id, $this->warehouse->id);
    expect((float) $bal->quantity)->toBe(10.0)
        ->and((float) $bal->weighted_avg_cost)->toBe(5.0)
        ->and(StockMovement::count())->toBe(1);
});

it('is idempotent on a repeated idempotency key', function () {
    $payload = [
        'item_id' => $this->item->id,
        'location_id' => $this->warehouse->id,
        'quantity' => 10,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => 5.0,
        'idempotency_key' => 'dup',
    ];

    $this->engine->post($payload);
    $this->engine->post($payload); // replay

    $bal = balanceFor($this->item->id, $this->warehouse->id);
    expect((float) $bal->quantity)->toBe(10.0)
        ->and(StockMovement::count())->toBe(1);
});

it('blends weighted-average cost across receipts', function () {
    $this->engine->post([
        'item_id' => $this->item->id, 'location_id' => $this->warehouse->id,
        'quantity' => 25, 'movement_type' => 'purchase', 'unit_cost_at_time' => 4.70, 'idempotency_key' => 'a',
    ]);
    $this->engine->post([
        'item_id' => $this->item->id, 'location_id' => $this->warehouse->id,
        'quantity' => 15, 'movement_type' => 'purchase', 'unit_cost_at_time' => 5.00, 'idempotency_key' => 'b',
    ]);

    $bal = balanceFor($this->item->id, $this->warehouse->id);
    expect((float) $bal->quantity)->toBe(40.0)
        ->and((float) $bal->weighted_avg_cost)->toBe(4.8125);
});

it('records a receipt against a PO, posts the ledger, and advances the PO', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();
    $poService = app(PurchaseOrderService::class);
    $purchaseService = app(PurchaseService::class);

    $po = $poService->create([
        'supplier_id' => $supplier->id,
        'destination_location_id' => $this->warehouse->id,
        'items' => [['item_id' => $this->item->id, 'ordered_qty' => 40, 'estimated_unit_cost' => 4.50]],
    ], $user);
    $po = $poService->submit($po);
    $line = $po->items->first();

    $purchase = $purchaseService->receive([
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplier->id,
        'destination_location_id' => $this->warehouse->id,
        'is_urgent_buy' => false,
        'received_at' => now()->toIso8601String(),
        'items' => [[
            'item_id' => $this->item->id,
            'purchase_order_item_id' => $line->id,
            'received_qty' => 25,
            'unit_cost_paid' => 4.70,
            'variance_reason' => 'Supplier short',
        ]],
    ], $user);

    $po->refresh();
    $bal = balanceFor($this->item->id, $this->warehouse->id);

    $pItem = $purchase->items->first();
    expect($po->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $po->items->first()->received_qty)->toBe(25.0)
        ->and((float) $bal->quantity)->toBe(25.0)
        ->and((float) $pItem->variance)->toBe(-15.0)
        ->and($pItem->variance_reason)->toBe('Supplier short')
        ->and((float) $pItem->expected_unit_cost)->toBe(4.50)
        ->and((float) $pItem->cost_variance)->toBe(0.20); // paid 4.70 vs est 4.50
});

it('blocks a non-urgent purchase with no PO', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();

    app(PurchaseService::class)->receive([
        'supplier_id' => $supplier->id,
        'destination_location_id' => $this->warehouse->id,
        'is_urgent_buy' => false,
        'received_at' => now()->toIso8601String(),
        'items' => [['item_id' => $this->item->id, 'received_qty' => 5, 'unit_cost_paid' => 2]],
    ], $user);
})->throws(App\Domain\Inventory\Exceptions\InventoryException::class);
