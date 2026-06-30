<?php

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Production\ProductionRunService;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(ProductionRunService::class);
    $this->user = User::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();

    $this->rawA = Item::factory()->create(); // cost 5
    $this->rawB = Item::factory()->create(); // cost 2
    $this->output = Item::factory()->create(['expiry_tracked' => false]);

    $seed = fn (Item $i, float $qty, float $cost, string $k) => $this->engine->post([
        'item_id' => $i->id, 'location_id' => $this->warehouse->id, 'quantity' => $qty,
        'movement_type' => 'purchase', 'unit_cost_at_time' => $cost, 'idempotency_key' => $k,
    ]);
    $seed($this->rawA, 100, 5, 'a');
    $seed($this->rawB, 50, 2, 'b');
});

function prodOnHand(int $itemId, int $locationId): float
{
    $r = DB::table('inventory_stock_balances')->where('item_id', $itemId)->where('location_id', $locationId)->first();

    return $r ? (float) $r->quantity : 0.0;
}

function prodCost(int $itemId, int $locationId): float
{
    $r = DB::table('inventory_stock_balances')->where('item_id', $itemId)->where('location_id', $locationId)->first();

    return $r ? (float) $r->weighted_avg_cost : 0.0;
}

it('consumes inputs and produces a costed output item', function () {
    $log = $this->service->record([
        'location_id' => $this->warehouse->id,
        'output_item_id' => $this->output->id,
        'output_qty' => 10,
        'inputs' => [
            ['item_id' => $this->rawA->id, 'quantity' => 4], // 4 × 5 = 20
            ['item_id' => $this->rawB->id, 'quantity' => 2], // 2 × 2 = 4
        ],
    ], $this->user);

    // Inputs down, output up.
    expect(prodOnHand($this->rawA->id, $this->warehouse->id))->toBe(96.0)
        ->and(prodOnHand($this->rawB->id, $this->warehouse->id))->toBe(48.0)
        ->and(prodOnHand($this->output->id, $this->warehouse->id))->toBe(10.0);

    // Output costed by inputs: (20 + 4) / 10 = 2.4
    expect((float) $log->input_cost_total)->toBe(24.0)
        ->and((float) $log->output_unit_cost)->toBe(2.4)
        ->and(prodCost($this->output->id, $this->warehouse->id))->toBe(2.4);

    // 2 input movements + 1 output movement, all type 'production'.
    expect(StockMovement::where('movement_type', 'production')->count())->toBe(3);
});

it('creates a batch for an expiry-tracked output', function () {
    $tracked = Item::factory()->create(['expiry_tracked' => true]);

    $this->service->record([
        'location_id' => $this->warehouse->id,
        'output_item_id' => $tracked->id,
        'output_qty' => 8,
        'expiry_date' => now()->addDays(5)->toDateString(),
        'inputs' => [['item_id' => $this->rawA->id, 'quantity' => 4]],
    ], $this->user);

    $batch = Batch::where('item_id', $tracked->id)->first();
    expect($batch)->not->toBeNull()
        ->and((float) $batch->remaining_qty)->toBe(8.0)
        ->and((float) $batch->unit_cost)->toBe(2.5) // (4×5)/8
        ->and($batch->expiry_date->toDateString())->toBe(now()->addDays(5)->toDateString());
});

it('blocks production when an input is short', function () {
    $this->service->record([
        'location_id' => $this->warehouse->id,
        'output_item_id' => $this->output->id,
        'output_qty' => 5,
        'inputs' => [['item_id' => $this->rawB->id, 'quantity' => 999]], // only 50 on hand
    ], $this->user);
})->throws(InventoryException::class);
