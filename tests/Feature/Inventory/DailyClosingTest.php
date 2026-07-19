<?php

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Enums\Inventory\DailyClosingStatus;
use App\Models\Inventory\DailyClosing;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\User;

beforeEach(function () {
    $this->engine = app(MovementPostingEngine::class);
    $this->service = app(DailyClosingService::class);
    $this->actor = User::factory()->create();
    $this->location = Location::factory()->warehouse()->create();
    $this->itemA = Item::factory()->create();
    $this->itemB = Item::factory()->create();

    foreach ([[$this->itemA, 40], [$this->itemB, 15]] as [$item, $qty]) {
        $this->engine->post([
            'item_id' => $item->id,
            'location_id' => $this->location->id,
            'quantity' => $qty,
            'movement_type' => 'purchase',
            'unit_cost_at_time' => 2.0,
            'idempotency_key' => "seed-{$item->id}",
        ]);
    }
});

it('opens a closing snapshotting expected quantities from the ledger', function () {
    $closing = $this->service->open($this->location->id, now()->toDateString(), $this->actor);

    expect($closing->status)->toBe(DailyClosingStatus::Open)
        ->and($closing->lines()->count())->toBe(2);

    $lineA = $closing->lines()->where('item_id', $this->itemA->id)->first();
    expect((float) $lineA->expected_qty)->toBe(40.0)
        ->and($lineA->counted_qty)->toBeNull();
});

it('is idempotent — re-opening returns the same closing', function () {
    $date = now()->toDateString();
    $a = $this->service->open($this->location->id, $date, $this->actor);
    $b = $this->service->open($this->location->id, $date, $this->actor);

    expect($b->id)->toBe($a->id)
        ->and(DailyClosing::count())->toBe(1);
});

it('computes variance from counted quantities', function () {
    $closing = $this->service->open($this->location->id, now()->toDateString(), $this->actor);
    $lineA = $closing->lines()->where('item_id', $this->itemA->id)->first();
    $lineB = $closing->lines()->where('item_id', $this->itemB->id)->first();

    $closing = $this->service->saveCounts($closing, [
        $lineA->id => 38, // 2 short
        $lineB->id => 15, // spot on
    ], complete: false, actor: $this->actor);

    expect((float) $lineA->fresh()->variance)->toBe(-2.0)
        ->and((float) $lineB->fresh()->variance)->toBe(0.0)
        ->and($closing->fresh()->status)->toBe(DailyClosingStatus::Open);
});

it('blocks completing until every line is counted', function () {
    $closing = $this->service->open($this->location->id, now()->toDateString(), $this->actor);
    $lineA = $closing->lines()->where('item_id', $this->itemA->id)->first();

    expect(fn () => $this->service->saveCounts($closing, [$lineA->id => 40], complete: true, actor: $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('completes when all lines are counted, then locks against further edits', function () {
    $closing = $this->service->open($this->location->id, now()->toDateString(), $this->actor);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->expected_qty])->all();

    $closing = $this->service->saveCounts($closing, $counts, complete: true, actor: $this->actor);
    expect($closing->status)->toBe(DailyClosingStatus::Completed)
        ->and($closing->completed_at)->not->toBeNull();

    $lineA = $closing->lines()->first();
    expect(fn () => $this->service->saveCounts($closing->fresh(), [$lineA->id => 1], complete: false, actor: $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('flags missed days on the calendar', function () {
    $today = now()->toDateString();
    $this->service->open($this->location->id, $today, $this->actor);

    $cal = $this->service->calendar($this->location->id, now()->subDays(2)->toDateString(), $today);
    $byDate = collect($cal)->keyBy('date');

    expect($cal)->toHaveCount(3)
        ->and($byDate[$today]['status'])->toBe('open')
        ->and($byDate[now()->subDays(2)->toDateString()]['status'])->toBeNull(); // missed
});

it('rejects opening a closing for a future date', function () {
    expect(fn () => $this->service->open($this->location->id, now()->addDay()->toDateString(), $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});
