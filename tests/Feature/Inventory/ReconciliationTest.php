<?php

use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Reconciliation\ReconciliationService;
use App\Enums\Inventory\ReconciliationStatus;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(ReconciliationService::class);
    $this->actor = User::factory()->create();
    $this->warehouse = Location::factory()->warehouse()->create();
    $this->itemA = Item::factory()->create();
    $this->itemB = Item::factory()->create();

    // itemA: 40 @ ₵5, itemB: 100 @ ₵30.
    foreach ([[$this->itemA, 40, 5.0], [$this->itemB, 100, 30.0]] as [$item, $qty, $cost]) {
        $this->engine->post([
            'item_id' => $item->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $qty,
            'movement_type' => 'purchase',
            'unit_cost_at_time' => $cost,
            'idempotency_key' => "seed-{$item->id}",
        ]);
    }
});

function reconBalance(int $itemId, int $locationId): float
{
    $q = DB::table('inventory_stock_balances')
        ->where('item_id', $itemId)->where('location_id', $locationId)->value('quantity');

    return $q !== null ? (float) $q : 0.0;
}

it('opens a cycle snapshotting system quantities and unit costs', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);

    expect($cycle->status)->toBe(ReconciliationStatus::Open)
        ->and($cycle->lines()->count())->toBe(2);

    $lineA = $cycle->lines()->where('item_id', $this->itemA->id)->first();
    expect((float) $lineA->system_qty)->toBe(40.0)
        ->and((float) $lineA->unit_cost)->toBe(5.0)
        ->and($lineA->counted_qty)->toBeNull();
});

it('allows only one open cycle per location', function () {
    $this->service->open($this->warehouse->id, $this->actor);

    expect(fn () => $this->service->open($this->warehouse->id, $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('computes variance and variance value from counts', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);
    $lineA = $cycle->lines()->where('item_id', $this->itemA->id)->first();
    $lineB = $cycle->lines()->where('item_id', $this->itemB->id)->first();

    $this->service->saveCounts($cycle, [$lineA->id => 38, $lineB->id => 100]);

    expect((float) $lineA->fresh()->variance)->toBe(-2.0)
        ->and((float) $lineA->fresh()->variance_value)->toBe(-10.0) // -2 × ₵5
        ->and((float) $lineB->fresh()->variance)->toBe(0.0);
});

it('posts cycle_adjustment movements that correct the ledger to the counted actual, then closes', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);
    $counts = $cycle->lines->mapWithKeys(
        fn ($l) => [$l->id => $l->item_id === $this->itemA->id ? 38.0 : 100.0],
    )->all();
    $cycle = $this->service->saveCounts($cycle, $counts);

    $cycle = $this->service->post($cycle, $this->actor);

    expect($cycle->status)->toBe(ReconciliationStatus::Closed)
        ->and($cycle->closed_at)->not->toBeNull()
        ->and(reconBalance($this->itemA->id, $this->warehouse->id))->toBe(38.0) // corrected
        ->and(reconBalance($this->itemB->id, $this->warehouse->id))->toBe(100.0) // unchanged
        ->and((float) $cycle->net_variance_value)->toBe(-10.0);

    // A single cycle_adjustment movement was written (only itemA had a variance).
    $movements = StockMovement::where('movement_type', 'cycle_adjustment')
        ->where('reference_type', 'inventory_reconciliation')
        ->where('reference_id', $cycle->id)->get();
    expect($movements)->toHaveCount(1)
        ->and((float) $movements->first()->quantity)->toBe(-2.0);

    $lineA = $cycle->lines()->where('item_id', $this->itemA->id)->first();
    expect($lineA->adjustment_movement_id)->not->toBeNull();
});

it('flags variances whose value exceeds the threshold', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);
    // itemB short by 20 @ ₵30 = ₵600 variance value → over the ₵500 default threshold.
    $counts = $cycle->lines->mapWithKeys(
        fn ($l) => [$l->id => $l->item_id === $this->itemB->id ? 80.0 : 40.0],
    )->all();
    $cycle = $this->service->saveCounts($cycle, $counts);
    $cycle = $this->service->post($cycle, $this->actor);

    $lineA = $cycle->lines()->where('item_id', $this->itemA->id)->first();
    $lineB = $cycle->lines()->where('item_id', $this->itemB->id)->first();
    expect($lineB->over_threshold)->toBeTrue()
        ->and($lineA->over_threshold)->toBeFalse();
});

it('blocks posting until every line is counted', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);
    $lineA = $cycle->lines()->where('item_id', $this->itemA->id)->first();
    $this->service->saveCounts($cycle, [$lineA->id => 40]); // itemB uncounted

    expect(fn () => $this->service->post($cycle, $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('resets the cycle — a new cycle opens on the corrected balances', function () {
    $cycle = $this->service->open($this->warehouse->id, $this->actor);
    $counts = $cycle->lines->mapWithKeys(
        fn ($l) => [$l->id => $l->item_id === $this->itemA->id ? 38.0 : 100.0],
    )->all();
    $this->service->post($this->service->saveCounts($cycle, $counts), $this->actor);

    // A closed cycle no longer accepts posting…
    expect(fn () => $this->service->post($cycle->fresh(), $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);

    // …but a fresh cycle can open, snapshotting the now-corrected system qty.
    $next = $this->service->open($this->warehouse->id, $this->actor);
    expect($next->id)->not->toBe($cycle->id);
    $lineA = $next->lines()->where('item_id', $this->itemA->id)->first();
    expect((float) $lineA->system_qty)->toBe(38.0);
});
