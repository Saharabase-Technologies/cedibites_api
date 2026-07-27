<?php

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Enums\Inventory\DailyClosingStatus;
use App\Models\Inventory\DailyClosing;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

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
    $closing = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);

    expect($closing->status)->toBe(DailyClosingStatus::Open)
        ->and($closing->lines()->count())->toBe(2);

    $lineA = $closing->lines()->where('item_id', $this->itemA->id)->first();
    expect((float) $lineA->expected_qty)->toBe(40.0)
        ->and($lineA->counted_qty)->toBeNull();
});

it('is idempotent — re-opening returns the same closing', function () {
    $date = DailyClosingService::currentBusinessDate();
    $a = $this->service->open($this->location->id, $date, $this->actor);
    $b = $this->service->open($this->location->id, $date, $this->actor);

    expect($b->id)->toBe($a->id)
        ->and(DailyClosing::count())->toBe(1);
});

it('computes variance from counted quantities', function () {
    $closing = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);
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
    $closing = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);
    $lineA = $closing->lines()->where('item_id', $this->itemA->id)->first();

    expect(fn () => $this->service->saveCounts($closing, [$lineA->id => 40], complete: true, actor: $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('completes when all lines are counted, then locks against further edits', function () {
    $closing = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->expected_qty])->all();

    $closing = $this->service->saveCounts($closing, $counts, complete: true, actor: $this->actor);
    expect($closing->status)->toBe(DailyClosingStatus::Completed)
        ->and($closing->completed_at)->not->toBeNull();

    $lineA = $closing->lines()->first();
    expect(fn () => $this->service->saveCounts($closing->fresh(), [$lineA->id => 1], complete: false, actor: $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

it('flags missed days on the calendar', function () {
    $today = DailyClosingService::currentBusinessDate();
    $this->service->open($this->location->id, $today, $this->actor);

    $cal = $this->service->calendar($this->location->id, Carbon::parse($today)->subDays(2)->toDateString(), $today);
    $byDate = collect($cal)->keyBy('date');

    expect($cal)->toHaveCount(3)
        ->and($byDate[$today]['status'])->toBe('open')
        ->and($byDate[Carbon::parse($today)->subDays(2)->toDateString()]['status'])->toBeNull(); // missed
});

it('rejects opening a closing for a future date', function () {
    expect(fn () => $this->service->open($this->location->id, Carbon::parse(DailyClosingService::currentBusinessDate())->addDay()->toDateString(), $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class);
});

/*
 * The past is the half that actually bites.
 *
 * A closing snapshots `expected_qty` from the ledger AS IT STANDS NOW, not as it
 * stood on the date at the top of the sheet. So opening a count for last Tuesday
 * compares Tuesday's physical shelf against today's expected figures and reads
 * the whole week's legitimate movements as variance - and then `count_adjustment`
 * posts that difference to make the books agree with it, corrupting the chain
 * that lets each morning open where the night before closed.
 *
 * Two open counts for past dates were sitting on production when this was found.
 */
it('rejects opening a closing for a past date, not just a future one', function () {
    expect(fn () => $this->service->open($this->location->id, Carbon::parse(DailyClosingService::currentBusinessDate())->subDays(3)->toDateString(), $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class, 'current business day');
});

it('still opens the current business day, and re-opening returns the same one', function () {
    $first = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);
    $again = $this->service->open($this->location->id, DailyClosingService::currentBusinessDate(), $this->actor);

    expect($again->id)->toBe($first->id);
});

it('defaults the business date to today when the client sends none', function () {
    // The rest of this file drives the service directly; this one has to go over
    // HTTP, because the point is that `business_date` is no longer required in
    // the request now that the screen has stopped asking for it.
    $this->seed(Database\Seeders\PermissionSeeder::class);
    $this->actor->givePermissionTo(App\Enums\Permission::InventoryDailyClosingEnter->value);

    $this->actingAs($this->actor, 'sanctum')
        ->postJson('/v1/inventory/daily-closings', ['location_id' => $this->location->id])
        ->assertSuccessful()
        ->assertJsonPath('data.business_date', DailyClosingService::currentBusinessDate());
});

/*
 * ── The business day runs past midnight ──────────────────────────────────────
 *
 * Branches trade into the evening and count up afterwards. Told at 00:30 that
 * "the date has changed, you can no longer close today", a manager either gives
 * up or back-dates it - and back-dating is the thing that corrupts the ledger.
 * So the day rolls at 03:00, not at midnight.
 *
 * Ghana is UTC+0 all year and `app.timezone` is UTC, so none of this is hiding
 * an offset bug. It would elsewhere.
 */
it('still calls it yesterday at one in the morning', function () {
    $businessDay = DailyClosingService::currentBusinessDate();

    // Half past midnight, the night after trading.
    $this->travelTo(Carbon::parse($businessDay)->addDay()->startOfDay()->addMinutes(30));

    expect(DailyClosingService::currentBusinessDate())->toBe($businessDay);

    // And the count for the day just worked can still be opened.
    $closing = $this->service->open($this->location->id, $businessDay, $this->actor);
    expect($closing->business_date->toDateString())->toBe($businessDay);
});

it('has rolled over by the time the next morning starts', function () {
    $businessDay = DailyClosingService::currentBusinessDate();

    $this->travelTo(Carbon::parse($businessDay)->addDay()->startOfDay()->addHours(9));

    expect(DailyClosingService::currentBusinessDate())->toBe(Carbon::parse($businessDay)->addDay()->toDateString())
        ->and(DailyClosingService::currentBusinessDate())->not->toBe($businessDay);

    // Yesterday is now genuinely closed to new counts.
    expect(fn () => $this->service->open($this->location->id, $businessDay, $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class, 'current business day');
});

/*
 * The safety net under that window.
 *
 * Closing yesterday at 01:00 is only safe because nothing moves overnight. If
 * something HAS moved, the shelf that was counted and the ledger being measured
 * against it describe different moments, and posting the difference would
 * silently cancel the movement out - a received delivery would simply vanish.
 */
it('refuses to settle a finished day once stock has moved since', function () {
    $businessDay = DailyClosingService::currentBusinessDate();
    $closing = $this->service->open($this->location->id, $businessDay, $this->actor);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->expected_qty])->all();

    // Next morning, after the day rolled - and a delivery has landed.
    $this->travelTo(Carbon::parse($businessDay)->addDay()->startOfDay()->addHours(9));
    $this->engine->post([
        'item_id' => $this->itemA->id,
        'location_id' => $this->location->id,
        'quantity' => 12,
        'movement_type' => 'transfer_in',
        'unit_cost_at_time' => 2.0,
        'idempotency_key' => 'morning-delivery',
    ]);

    expect(fn () => $this->service->saveCounts($closing->fresh(), $counts, complete: true, actor: $this->actor))
        ->toThrow(App\Domain\Inventory\Exceptions\InventoryException::class, 'no longer be settled');

    // And critically, the delivery is still on the shelf.
    expect((float) DB::table('inventory_stock_balances')
        ->where('location_id', $this->location->id)
        ->where('item_id', $this->itemA->id)
        ->value('quantity'))->toBe(52.0);
});

it('settles a finished day happily when nothing has moved', function () {
    $businessDay = DailyClosingService::currentBusinessDate();
    $closing = $this->service->open($this->location->id, $businessDay, $this->actor);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->expected_qty - 1])->all();

    // 01:00, the small hours of the same working night. Nothing has moved.
    $this->travelTo(Carbon::parse($businessDay)->addDay()->startOfDay()->addMinutes(30));

    $closing = $this->service->saveCounts($closing->fresh(), $counts, complete: true, actor: $this->actor);
    expect($closing->status)->toBe(DailyClosingStatus::Completed);
});

/*
 * A count finished at 00:30 belongs to the day it counted. Stamping the
 * adjustment `now()` would file it under tomorrow and leave every
 * movement-by-date report disagreeing with the closings list by one day.
 */
it('dates the closing adjustment to the day being counted, not the wall clock', function () {
    $businessDay = DailyClosingService::currentBusinessDate();
    $closing = $this->service->open($this->location->id, $businessDay, $this->actor);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->expected_qty - 3])->all();

    $this->travelTo(Carbon::parse($businessDay)->addDay()->startOfDay()->addMinutes(30));
    $this->service->saveCounts($closing->fresh(), $counts, complete: true, actor: $this->actor);

    $adjustment = App\Models\Inventory\StockMovement::where('movement_type', 'count_adjustment')
        ->where('location_id', $this->location->id)
        ->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->occurred_at->toDateString())->toBe($businessDay);
});
