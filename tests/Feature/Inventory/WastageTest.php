<?php

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Transfers\TransferService;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\TransferStatus;
use App\Enums\Inventory\WastageOrigin;
use App\Enums\Inventory\WastageReason;
use App\Enums\Inventory\WastageStatus;
use App\Enums\Permission;
use App\Http\Controllers\Api\Inventory\InventorySettingController;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Transfer;
use App\Models\Inventory\Wastage;
use App\Models\User;
use App\Services\SystemSettingService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Wastage — the named half of every loss, and the mechanism that lets a branch
 * close its day neutral.
 *
 * The cast matches the client's own walkthrough: Jesse manages a branch, Wilfred
 * runs the mother kitchen and answers for what he supplied.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->engine = app(MovementPostingEngine::class);
    $this->wastages = app(WastageService::class);
    $this->transfers = app(TransferService::class);
    $this->closings = app(DailyClosingService::class);

    $this->warehouse = Location::factory()->warehouse()->create();
    $branch = Branch::factory()->create();
    $this->branch = Location::factory()->satellite()->create(['branch_id' => $branch->id]);

    // Wilfred runs the mother kitchen: sees everywhere, works at warehouses.
    $this->wilfred = User::factory()->create();
    $this->wilfred->givePermissionTo(Permission::InventoryViewAllLocations->value);

    // Jesse works at the branch and nowhere else.
    $this->jesse = User::factory()->create();
    Employee::factory()->create(['user_id' => $this->jesse->id])
        ->branches()->attach($branch->id);

    $this->rice = Item::factory()->create(['name' => 'Rice']);
    $this->chicken = Item::factory()->create(['name' => 'Chicken']);

    // Cheap rice, expensive chicken — so one story stays under the ₵500
    // threshold and the other blows straight through it.
    seedStock($this->branch->id, $this->rice->id, 40, 2.0);
    seedStock($this->branch->id, $this->chicken->id, 30, 70.0);
    seedStock($this->warehouse->id, $this->rice->id, 500, 2.0);
});

/** Seed stock at a location. */
function seedStock(int $locationId, int $itemId, float $qty, float $cost): void
{
    app(MovementPostingEngine::class)->post([
        'item_id' => $itemId,
        'location_id' => $locationId,
        'quantity' => $qty,
        'movement_type' => 'purchase',
        'unit_cost_at_time' => $cost,
        'idempotency_key' => "seed-{$locationId}-{$itemId}-".uniqid(),
    ]);
}

function onHandAt(int $locationId, int $itemId): float
{
    return (float) (DB::table('inventory_stock_balances')
        ->where('location_id', $locationId)->where('item_id', $itemId)
        ->value('quantity') ?? 0);
}

// ── Story A: the small everyday one ──────────────────────────────────────────

it('writes off a small loss on the spot, without asking anybody', function () {
    // A tray of rice burns. 2 kg at ₵2 = ₵4, nowhere near the threshold.
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [[
            'item_id' => $this->rice->id,
            'quantity' => 2,
            'reason' => WastageReason::Burnt->value,
        ]],
    ], $this->jesse);

    expect($wastage->status)->toBe(WastageStatus::Approved)
        ->and($wastage->requires_approval)->toBeFalse()
        ->and((float) $wastage->total_value)->toBe(4.0)
        // The stock is gone now — not when somebody gets round to it.
        ->and(onHandAt($this->branch->id, $this->rice->id))->toBe(38.0);

    expect(StockMovement::where('movement_type', 'wastage')
        ->where('reference_id', $wastage->id)->sum('quantity'))->toEqual(-2);
});

it('refuses to let anyone waste more than they hold', function () {
    expect(fn () => $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->rice->id, 'quantity' => 999, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse))->toThrow(InventoryException::class, 'cannot waste more');
});

it('adds up two lines of the same item before checking what is on hand', function () {
    // 30 + 30 of a 40 stock: each line passes alone, the pair must not.
    expect(fn () => $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [
            ['item_id' => $this->rice->id, 'quantity' => 30, 'reason' => WastageReason::Burnt->value],
            ['item_id' => $this->rice->id, 'quantity' => 30, 'reason' => WastageReason::Spillage->value],
        ],
    ], $this->jesse))->toThrow(InventoryException::class, 'cannot waste more');
});

it('makes "Other" say what happened', function () {
    expect(fn () => $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->rice->id, 'quantity' => 1, 'reason' => WastageReason::Other->value]],
    ], $this->jesse))->toThrow(InventoryException::class, 'add a note');
});

// ── Evidence: "show me the food that has gone bad" ───────────────────────────

it('will not let a big loss be written off with nothing to look at', function () {
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $return = Transfer::find($wastage->return_transfer_id);
    $this->transfers->send($return->fresh('lines'), $this->jesse);
    $this->transfers->receive($return->fresh('lines'), $this->wilfred);

    expect(fn () => $this->wastages->approve($wastage->fresh(), $this->wilfred))
        ->toThrow(InventoryException::class, 'no photo of these goods');
});

it('lets it through once there is a photo, and keeps both sides of the argument', function () {
    Storage::fake('public');

    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    // Jesse photographs the goods before they leave — that is his case.
    $this->wastages->attachPhoto(
        $wastage,
        UploadedFile::fake()->image('bad-chicken.jpg'),
        $this->jesse,
        'Smelled off on opening',
    );

    $return = Transfer::find($wastage->return_transfer_id);
    $this->transfers->send($return->fresh('lines'), $this->jesse);
    $this->transfers->receive($return->fresh('lines'), $this->wilfred);

    // Wilfred photographs what actually arrived — that is his.
    $this->wastages->attachPhoto(
        $wastage->fresh(),
        UploadedFile::fake()->image('on-arrival.jpg'),
        $this->wilfred,
    );

    $photos = $wastage->fresh('photos')->photos;
    expect($photos)->toHaveCount(2)
        ->and($photos->firstWhere('uploaded_by', $this->jesse->id)->stage)->toBe('declared')
        ->and($photos->firstWhere('uploaded_by', $this->wilfred->id)->stage)->toBe('inspection');

    Storage::disk('public')->assertExists($photos->first()->path);

    $this->wastages->approve($wastage->fresh(), $this->wilfred);
    expect($wastage->fresh()->status)->toBe(WastageStatus::Approved);
});

it('keeps the evidence intact once the claim is settled', function () {
    Storage::fake('public');

    // Small claim — self-approves immediately, so it is already settled.
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->rice->id, 'quantity' => 2, 'reason' => WastageReason::Burnt->value]],
    ], $this->jesse);

    expect(fn () => $this->wastages->attachPhoto($wastage, UploadedFile::fake()->image('late.jpg'), $this->jesse))
        ->toThrow(InventoryException::class, 'already settled');
});

it('will not let one side delete the other side\'s photo', function () {
    Storage::fake('public');

    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $photo = $this->wastages->attachPhoto($wastage, UploadedFile::fake()->image('jesse.jpg'), $this->jesse);

    expect(fn () => $this->wastages->detachPhoto($wastage->fresh(), $photo, $this->wilfred))
        ->toThrow(InventoryException::class, 'Only whoever uploaded');
});

// ── The threshold is admin-editable, and everything reads it live ────────────

it('honours a threshold the admin has changed', function () {
    app(SystemSettingService::class)->set(InventorySettingController::THRESHOLD_KEY, '10', 'string');

    // 2 kg of rice at ₵2 = ₵4 — under the default ₵500, but the bar is ₵10 now,
    // so it still self-approves.
    $small = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->rice->id, 'quantity' => 2, 'reason' => WastageReason::Burnt->value]],
    ], $this->jesse);
    expect($small->status)->toBe(WastageStatus::Approved);

    // 10 kg = ₵20, which now clears the lowered bar and needs the goods back.
    $big = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->rice->id, 'quantity' => 10, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    expect($big->status)->toBe(WastageStatus::PendingReturn)
        ->and((float) $big->threshold_amount)->toBe(10.0);
});

// ── Story B: over the threshold, the goods go back ───────────────────────────

it('sends the goods back to the warehouse before a big loss can be written off', function () {
    Storage::fake('public');

    // 20 kg of chicken at ₵70 = ₵1,400. Well over ₵500.
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [[
            'item_id' => $this->chicken->id,
            'quantity' => 20,
            'reason' => WastageReason::Spoiled->value,
        ]],
        'notes' => 'Arrived smelling off.',
    ], $this->jesse);

    expect($wastage->status)->toBe(WastageStatus::PendingReturn)
        ->and($wastage->requires_return)->toBeTrue()
        ->and((int) $wastage->disposal_location_id)->toBe($this->warehouse->id)
        // Nothing has been written off yet — the claim is unproven.
        ->and(onHandAt($this->branch->id, $this->chicken->id))->toBe(30.0);

    $this->wastages->attachPhoto($wastage, UploadedFile::fake()->image('bad.jpg'), $this->jesse);

    $return = Transfer::find($wastage->return_transfer_id);
    expect($return)->not->toBeNull()
        ->and($return->status)->toBe(TransferStatus::Approved)
        ->and((int) $return->source_location_id)->toBe($this->branch->id)
        ->and((int) $return->destination_location_id)->toBe($this->warehouse->id);

    // Jesse ships it back; Wilfred signs for it at the warehouse.
    $this->transfers->send($return->fresh('lines'), $this->jesse);
    expect(onHandAt($this->branch->id, $this->chicken->id))->toBe(10.0);

    $this->transfers->receive($return->fresh('lines'), $this->wilfred);

    $wastage->refresh();
    expect($wastage->status)->toBe(WastageStatus::PendingApproval)
        ->and(onHandAt($this->warehouse->id, $this->chicken->id))->toBe(20.0);

    // Now, with the goods in front of him, Wilfred agrees.
    $this->wastages->approve($wastage, $this->wilfred);

    expect($wastage->fresh()->status)->toBe(WastageStatus::Approved)
        // Written down where the goods actually are.
        ->and(onHandAt($this->warehouse->id, $this->chicken->id))->toBe(0.0)
        ->and(onHandAt($this->branch->id, $this->chicken->id))->toBe(10.0);
});

it('leaves the goods in warehouse stock when the claim is refused', function () {
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $return = Transfer::find($wastage->return_transfer_id);
    $this->transfers->send($return->fresh('lines'), $this->jesse);
    $this->transfers->receive($return->fresh('lines'), $this->wilfred);

    // Note: no photo. Refusing must stay possible without one — a claim with no
    // evidence is precisely what a refusal is for, and requiring a photo to say
    // "no" would trap unevidenced claims open forever.
    $this->wastages->reject($wastage->fresh(), $this->wilfred, 'Looks fine to me.');

    expect($wastage->fresh()->status)->toBe(WastageStatus::Rejected)
        // Nothing written off; the chicken is the warehouse's again.
        ->and(onHandAt($this->warehouse->id, $this->chicken->id))->toBe(20.0)
        ->and(StockMovement::where('movement_type', 'wastage')->count())->toBe(0);
});

it('will not let the person who declared a loss approve it', function () {
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $return = Transfer::find($wastage->return_transfer_id);
    $this->transfers->send($return->fresh('lines'), $this->jesse);
    $this->transfers->receive($return->fresh('lines'), $this->wilfred);

    expect(fn () => $this->wastages->approve($wastage->fresh(), $this->jesse))
        ->toThrow(InventoryException::class, 'someone else has to approve it');
});

it('will not approve a claim whose goods have not come back yet', function () {
    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    expect(fn () => $this->wastages->approve($wastage, $this->wilfred))
        ->toThrow(InventoryException::class, 'have not come back');
});

// ── Story D: the warehouse manager answers to nobody above him ───────────────

it('lets the warehouse manager write off his own stock at any value', function () {
    seedStock($this->warehouse->id, $this->chicken->id, 40, 70.0);

    // ₵2,800 — far over the threshold, but there is nobody above him.
    $wastage = $this->wastages->record([
        'location_id' => $this->warehouse->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 40, 'reason' => WastageReason::Expired->value]],
    ], $this->wilfred);

    expect($wastage->status)->toBe(WastageStatus::Approved)
        ->and($wastage->requires_return)->toBeFalse()
        ->and(onHandAt($this->warehouse->id, $this->chicken->id))->toBe(0.0);

    // The admin still hears about it.
    expect(DB::table('inventory_alerts')->where('type', 'wastage_threshold')->count())->toBe(1);
});

// ── Story C: the shortfall nobody is chasing ─────────────────────────────────

it('files a written-off transfer shortfall as wastage without moving stock twice', function () {
    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);

    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $line = $transfer->fresh('lines')->lines->first();
    // Only 8 of the 10 turn up.
    $this->transfers->receive($transfer->fresh('lines'), $this->jesse, [$line->id => 8]);

    $before = onHandAt($this->warehouse->id, $this->rice->id);
    $this->transfers->resolveDispute($transfer->fresh('lines'), $this->wilfred, 'Gone. Stop chasing it.', sendCorrective: false);

    $wastage = Wastage::where('origin', WastageOrigin::TransferShortfall->value)->first();
    expect($wastage)->not->toBeNull()
        ->and($wastage->status)->toBe(WastageStatus::Approved)
        ->and((int) $wastage->location_id)->toBe($this->warehouse->id)
        ->and((float) $wastage->lines->first()->quantity)->toBe(2.0)
        // THE POINT: the ledger already recorded this loss when the stock left
        // and never arrived. Classifying it must not deduct it a second time.
        ->and(onHandAt($this->warehouse->id, $this->rice->id))->toBe($before)
        ->and($wastage->lines->first()->movement_id)->toBeNull();
});

// ── Refusing a delivery at the door ──────────────────────────────────────────

it('sends refused goods straight back to the sender instead of calling them missing', function () {
    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);
    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $warehouseAfterSend = onHandAt($this->warehouse->id, $this->rice->id);
    $line = $transfer->fresh('lines')->lines->first();

    // 7 accepted, 3 turned away at the door. Nothing is missing.
    $updated = $this->transfers->receive(
        $transfer->fresh('lines'),
        $this->jesse,
        [$line->id => 7],
        null,
        [$line->id => ['qty' => 3, 'reason' => WastageReason::Spoiled->value]],
    );

    expect($updated->status)->toBe(TransferStatus::Received)
        // No dispute: both ends agree on where every grain went.
        ->and($updated->dispute()->count())->toBe(0)
        ->and(onHandAt($this->branch->id, $this->rice->id))->toBe(47.0)
        ->and(onHandAt($this->warehouse->id, $this->rice->id))->toBe($warehouseAfterSend + 3);

    // And it lands in the sender's queue to decide on.
    $claim = Wastage::where('origin', WastageOrigin::DeliveryRejection->value)->first();
    expect($claim)->not->toBeNull()
        ->and($claim->status)->toBe(WastageStatus::PendingApproval)
        ->and((int) $claim->location_id)->toBe($this->warehouse->id)
        ->and((float) $claim->lines->first()->quantity)->toBe(3.0);
});

it('lets the branch that refused a delivery see the claim and photograph it', function () {
    Storage::fake('public');

    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);
    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $line = $transfer->fresh('lines')->lines->first();
    $this->transfers->receive(
        $transfer->fresh('lines'),
        $this->jesse,
        [$line->id => 7],
        null,
        [$line->id => ['qty' => 3, 'reason' => WastageReason::Spoiled->value]],
    );

    $claim = Wastage::where('origin', WastageOrigin::DeliveryRejection->value)->firstOrFail();

    // The goods are the warehouse's problem, but Jesse is the only person who
    // saw what was wrong with them. If he cannot open the claim he cannot
    // photograph the evidence, and the claim is unresolvable by design.
    expect((int) $claim->location_id)->toBe($this->warehouse->id)
        ->and((int) $claim->claimant_location_id)->toBe($this->branch->id)
        ->and($claim->isVisibleTo($this->jesse))->toBeTrue()
        ->and($claim->isVisibleTo($this->wilfred))->toBeTrue();

    $jesses = $this->wastages->attachPhoto(
        $claim,
        UploadedFile::fake()->image('mouldy.jpg'),
        $this->jesse,
        'Mould on the sacks',
    );
    $wilfreds = $this->wastages->attachPhoto(
        $claim->fresh(),
        UploadedFile::fake()->image('back-at-the-warehouse.jpg'),
        $this->wilfred,
    );

    // Refusing the delivery IS raising the claim, so Jesse is the claimant and
    // his photo is his own case. Wilfred, looking at the returned goods
    // afterwards, is the one inspecting.
    expect($jesses->stage)->toBe('declared')
        ->and($wilfreds->stage)->toBe('inspection')
        ->and($claim->fresh('photos')->photos)->toHaveCount(2);
});

it('marks a wholly refused consignment as rejected, not disputed', function () {
    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);
    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $line = $transfer->fresh('lines')->lines->first();
    $updated = $this->transfers->receive(
        $transfer->fresh('lines'),
        $this->jesse,
        [$line->id => 0],
        'The whole lot is off.',
        [$line->id => ['qty' => 10, 'reason' => WastageReason::Spoiled->value]],
    );

    expect($updated->status)->toBe(TransferStatus::Rejected)
        ->and($updated->rejected_by)->toBe($this->jesse->id)
        // Never entered the branch's books at all.
        ->and(onHandAt($this->branch->id, $this->rice->id))->toBe(40.0)
        ->and(onHandAt($this->warehouse->id, $this->rice->id))->toBe(500.0);
});

it('refuses a refusal that does not say what was wrong', function () {
    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);
    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $line = $transfer->fresh('lines')->lines->first();
    expect(fn () => $this->transfers->receive(
        $transfer->fresh('lines'),
        $this->jesse,
        [$line->id => 7],
        null,
        [$line->id => ['qty' => 3]],
    ))->toThrow(InventoryException::class, 'what is wrong');
});

it('still calls genuinely missing stock a dispute', function () {
    $transfer = $this->transfers->create([
        'source_location_id' => $this->warehouse->id,
        'destination_location_id' => $this->branch->id,
        'items' => [['item_id' => $this->rice->id, 'requested_qty' => 10]],
    ], $this->wilfred);
    $this->transfers->submit($transfer, $this->wilfred);
    $this->transfers->approve($transfer->fresh(), $this->wilfred);
    $this->transfers->send($transfer->fresh('lines'), $this->wilfred);

    $line = $transfer->fresh('lines')->lines->first();
    // 6 accepted, 1 refused, 3 unaccounted for.
    $updated = $this->transfers->receive(
        $transfer->fresh('lines'),
        $this->jesse,
        [$line->id => 6],
        null,
        [$line->id => ['qty' => 1, 'reason' => WastageReason::DamagedInTransit->value]],
    );

    expect($updated->status)->toBe(TransferStatus::Disputed)
        // The refused unit is accounted for; only the 3 nobody can find count.
        ->and((float) $updated->dispute->discrepancy_qty)->toBe(3.0);
});

// ── The point of all of it: tomorrow opens where tonight closed ──────────────

it('leaves the ledger reading exactly what was counted, so tomorrow opens there', function () {
    $closing = $this->closings->open($this->branch->id, now()->toDateString(), $this->jesse);
    $riceLine = $closing->lines()->where('item_id', $this->rice->id)->first();
    $chickenLine = $closing->lines()->where('item_id', $this->chicken->id)->first();

    // The shelf says 37 rice (3 short) and 30 chicken (spot on).
    $this->closings->saveCounts($closing, [
        $riceLine->id => ['counted_qty' => 37, 'reason' => WastageReason::Spillage->value],
        $chickenLine->id => ['counted_qty' => 30],
    ], complete: true, actor: $this->jesse);

    // The books now agree with the shelf. Five boxes counted, five boxes
    // tomorrow — the founder's whole requirement in one assertion.
    expect(onHandAt($this->branch->id, $this->rice->id))->toBe(37.0)
        ->and(onHandAt($this->branch->id, $this->chicken->id))->toBe(30.0);

    expect(StockMovement::where('movement_type', 'count_adjustment')
        ->where('reference_id', $closing->id)->sum('quantity'))->toEqual(-3);

    // Opening tomorrow starts from the counted actual, not from yesterday's hope.
    $this->travel(1)->days();
    $tomorrow = $this->closings->open($this->branch->id, now()->toDateString(), $this->jesse);
    expect((float) $tomorrow->lines()->where('item_id', $this->rice->id)->first()->expected_qty)->toBe(37.0);
});

it('files the explained part of a shortfall as wastage but never deducts it twice', function () {
    $closing = $this->closings->open($this->branch->id, now()->toDateString(), $this->jesse);
    $riceLine = $closing->lines()->where('item_id', $this->rice->id)->first();
    $chickenLine = $closing->lines()->where('item_id', $this->chicken->id)->first();

    $this->closings->saveCounts($closing, [
        $riceLine->id => ['counted_qty' => 37, 'reason' => WastageReason::Spillage->value],
        $chickenLine->id => ['counted_qty' => 30],
    ], complete: true, actor: $this->jesse);

    $wastage = Wastage::where('origin', WastageOrigin::DailyClosing->value)->first();
    expect($wastage)->not->toBeNull()
        ->and($wastage->lines)->toHaveCount(1)
        ->and((float) $wastage->lines->first()->quantity)->toBe(3.0)
        ->and($wastage->lines->first()->reason)->toBe(WastageReason::Spillage)
        // Classification only — the count adjustment already moved the stock.
        ->and($wastage->lines->first()->movement_id)->toBeNull()
        ->and(StockMovement::where('movement_type', 'wastage')->count())->toBe(0)
        ->and(onHandAt($this->branch->id, $this->rice->id))->toBe(37.0);
});

it('measures the count against the ledger as it stands at completion, not at opening', function () {
    // Jesse opens the count first thing in the morning...
    $closing = $this->closings->open($this->branch->id, now()->toDateString(), $this->jesse);
    $riceLine = $closing->lines()->where('item_id', $this->rice->id)->first();
    expect((float) $riceLine->expected_qty)->toBe(40.0);

    // ...then the branch trades all day and 10 kg legitimately goes out.
    $this->engine->post([
        'item_id' => $this->rice->id,
        'location_id' => $this->branch->id,
        'quantity' => -10,
        'movement_type' => 'sale',
        'idempotency_key' => 'day-of-trading',
    ]);

    $chickenLine = $closing->lines()->where('item_id', $this->chicken->id)->first();
    $this->closings->saveCounts($closing, [
        $riceLine->id => ['counted_qty' => 30],
        $chickenLine->id => ['counted_qty' => 30],
    ], complete: true, actor: $this->jesse);

    // 30 counted against 30 expected is a clean day — NOT a 10 kg loss measured
    // against a figure from before the shop opened.
    expect((float) $riceLine->fresh()->variance)->toBe(0.0)
        ->and((float) $riceLine->fresh()->expected_qty)->toBe(30.0)
        ->and(StockMovement::where('movement_type', 'count_adjustment')->count())->toBe(0);
});

it('leaves an unexplained shortfall unexplained rather than blocking the day', function () {
    $closing = $this->closings->open($this->branch->id, now()->toDateString(), $this->jesse);
    $counts = $closing->lines->mapWithKeys(fn ($l) => [
        $l->id => ['counted_qty' => (float) $l->expected_qty - 1],
    ])->all();

    $closing = $this->closings->saveCounts($closing, $counts, complete: true, actor: $this->jesse);

    // The day closes, the books are corrected, and nobody has been forced to
    // invent a reason they do not have.
    expect($closing->status->value)->toBe('completed')
        ->and($closing->wastage_id)->toBeNull()
        ->and(onHandAt($this->branch->id, $this->rice->id))->toBe(39.0);
});

/*
 * ── Media weight ─────────────────────────────────────────────────────────────
 *
 * Measured on production after the first phone field test: one claim held six
 * photos totalling ~14 MB, the largest 6.2 MB, and opening the claim pulled all
 * of it to draw six 112px squares.
 */
it('builds a small thumbnail and a display copy, and never touches the original', function () {
    Storage::fake('public');

    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $photo = $this->wastages->attachPhoto(
        $wastage,
        UploadedFile::fake()->image('crate.jpg', 3000, 2000),
        $this->jesse,
    );

    $disk = Storage::disk('public');

    expect($photo->thumb_path)->not->toBeNull()
        ->and($photo->display_path)->not->toBeNull();

    $disk->assertExists($photo->path);
    $disk->assertExists($photo->thumb_path);
    $disk->assertExists($photo->display_path);

    // The point of the exercise: the grid must not cost what the original costs.
    expect($disk->size($photo->thumb_path))->toBeLessThan($disk->size($photo->path))
        ->and($disk->size($photo->thumb_path))->toBeLessThan($disk->size($photo->display_path));

    // The original is the evidence. It is not re-encoded, resized or replaced.
    expect(getimagesize($disk->path($photo->path))[0])->toBe(3000)
        ->and(getimagesize($disk->path($photo->thumb_path))[0])
        ->toBe(App\Services\Media\EvidenceImageProcessor::THUMB_WIDTH);
});

it('leaves video alone rather than pretending to transcode it', function () {
    Storage::fake('public');

    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $photo = $this->wastages->attachPhoto(
        $wastage,
        UploadedFile::fake()->create('crate.mp4', 900, 'video/mp4'),
        $this->jesse,
    );

    // No ffmpeg on the VPS, so no renditions - and the resource falls back to
    // the original rather than serving a broken URL.
    expect($photo->thumb_path)->toBeNull()
        ->and($photo->display_path)->toBeNull();

    $body = (new App\Http\Resources\Inventory\WastageResource(
        $wastage->fresh(['photos.uploadedBy'])
    ))->toArray(request());

    expect($body['photos'][0]['thumb_url'])->toBe($photo->url)
        ->and($body['photos'][0]['display_url'])->toBe($photo->url);
});

it('takes the renditions with it when a photo is removed', function () {
    Storage::fake('public');

    $wastage = $this->wastages->record([
        'location_id' => $this->branch->id,
        'lines' => [['item_id' => $this->chicken->id, 'quantity' => 20, 'reason' => WastageReason::Spoiled->value]],
    ], $this->jesse);

    $photo = $this->wastages->attachPhoto($wastage, UploadedFile::fake()->image('crate.jpg'), $this->jesse);
    [$original, $thumb, $display] = [$photo->path, $photo->thumb_path, $photo->display_path];

    $this->wastages->detachPhoto($wastage->fresh(), $photo, $this->jesse);

    Storage::disk('public')->assertMissing($original);
    Storage::disk('public')->assertMissing($thumb);
    Storage::disk('public')->assertMissing($display);
});
