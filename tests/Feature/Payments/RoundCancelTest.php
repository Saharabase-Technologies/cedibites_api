<?php

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRound;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payments\ShiftMoney;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| A round cancelled on its own (D40, D45)
|--------------------------------------------------------------------------
|
| Staff ask, an admin approves or rejects, an admin may cancel outright. The
| round's price comes off the order and its stock comes back. A payment taken
| with the round is refunded; money paid inside a bigger payment is owed
| back, and the cashier who hands it over records it.
|
*/

function rcStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function rcDish(Branch $branch, string $name): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

/** Ring a dine-in sale, or a round of one, and confirm cash as the till does. */
function rcRing(User $cashier, Branch $branch, MenuItem $dish, array $overrides = [])
{
    $body = array_merge([
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 5,
        ]],
        'payment_method' => 'cash',
        'fulfillment_type' => 'dine_in',
        'contact_name' => 'Ama',
        'contact_phone' => '+233247879103',
    ], $overrides);

    $session = test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', $body);

    if ($body['payment_method'] !== 'cash') {
        return $session;
    }

    return test()->actingAs($cashier)->postJson(
        '/v1/pos/checkout-sessions/'.$session->json('session_token').'/confirm-cash',
        ['amount_paid' => 500],
    );
}

function rcAsk(User $by, Order $order, OrderRound $round, string $reason = 'Added to the wrong table')
{
    return test()->actingAs($by)->postJson("/v1/employee/orders/{$order->id}/rounds/{$round->id}/request-cancel", ['reason' => $reason]);
}

function rcApprove(User $admin, Order $order, OrderRound $round)
{
    return test()->actingAs($admin)->postJson("/v1/admin/orders/{$order->id}/rounds/{$round->id}/approve-cancel");
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    Notification::fake();

    $this->branch = Branch::factory()->create([
        'name' => 'Lakeside',
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = rcStaff($this->branch, 'Rosina');
    ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = rcStaff($this->branch, 'Kofi');
    ['user' => $this->admin] = rcStaff($this->branch, 'Head Office', RoleEnum::Admin->value);

    // Ama's table: jollof, paid in cash by Rosina.
    $this->jollof = rcDish($this->branch, 'Jollof');
    $this->water = rcDish($this->branch, 'Water');
    $this->order = Order::findOrFail(rcRing($this->rosina, $this->branch, $this->jollof)->assertCreated()->json('order.id'));
    $this->firstTotal = (float) $this->order->total_amount;
    $this->waterPrice = (float) $this->water->options->first()->price;
});

/** Kofi adds the water, paid in cash or with Pay later. */
function rcAddWater(string $method = 'cash'): OrderRound
{
    $t = test();
    rcRing($t->kofi, $t->branch, $t->water, ['add_to_order_id' => $t->order->id, 'payment_method' => $method])->assertCreated();

    return $t->order->rounds()->latest('id')->firstOrFail();
}

describe('asking for a round\'s cancel', function () {
    it('marks the round asked, keeps it where it was, and lists it for head office', function () {
        $round = rcAddWater();
        $status = $round->status;

        rcAsk($this->kofi, $this->order, $round)
            ->assertOk()
            ->assertJsonPath('data.rounds.0.cancel_request_reason', 'Added to the wrong table');

        $round->refresh();
        expect($round->cancel_requested_at)->not->toBeNull()
            ->and($round->cancel_requested_by)->toBe($this->kofi->id)
            ->and($round->status)->toBe($status);

        $this->actingAs($this->admin)
            ->getJson('/v1/admin/round-cancel-requests')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $this->order->order_number)
            ->assertJsonPath('data.0.round', 2)
            ->assertJsonPath('data.0.id', $round->id)
            ->assertJsonPath('data.0.requested_by_name', 'Kofi');

        Notification::assertSentTo($this->admin, \App\Notifications\CancelRequestedNotification::class, fn ($n) => $n->round?->id === $round->id);
    });

    it('holds the round in the kitchen while its cancel is asked', function () {
        $round = rcAddWater();
        rcAsk($this->kofi, $this->order, $round)->assertOk();

        $this->actingAs($this->kofi)
            ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$round->id}/status", ['status' => 'preparing'])
            ->assertStatus(422);
    });

    it('is refused once the round is ready, and twice for the same round', function () {
        $round = rcAddWater();
        rcAsk($this->kofi, $this->order, $round)->assertOk();
        rcAsk($this->kofi, $this->order, $round)->assertStatus(422);

        $other = rcAddWater();
        $other->update(['status' => 'ready']);
        rcAsk($this->kofi, $this->order, $other)->assertStatus(422);
    });

    it('does not let another branch\'s staff ask', function () {
        $round = rcAddWater();
        $elsewhere = Branch::factory()->create(['is_active' => true]);
        ['user' => $stranger] = rcStaff($elsewhere, 'Yaw');

        rcAsk($stranger, $this->order, $round)->assertForbidden();
    });

    it('lets the round carry on when head office rejects it', function () {
        $round = rcAddWater();
        rcAsk($this->kofi, $this->order, $round)->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/orders/{$this->order->id}/rounds/{$round->id}/reject-cancel")
            ->assertOk();

        expect($round->fresh()->cancel_requested_at)->toBeNull();
        $this->actingAs($this->kofi)
            ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$round->id}/status", ['status' => 'preparing'])
            ->assertOk();
    });

    it('is approved by head office only', function () {
        $round = rcAddWater();
        rcAsk($this->kofi, $this->order, $round)->assertOk();

        rcApprove($this->kofi, $this->order, $round)->assertForbidden();
    });
});

describe('a cancelled round', function () {
    it('comes off the order with its own payment refunded and only its own stock back (case 1)', function () {
        $round = rcAddWater();
        rcAsk($this->kofi, $this->order, $round)->assertOk();

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('reverseForRound')->once();
        $stock->shouldNotReceive('reverseForOrder');

        rcApprove($this->admin, $this->order, $round)
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'paid')
            ->assertJsonPath('data.to_give_back', 0);

        $order = $this->order->fresh(['payments']);
        expect($round->fresh()->status)->toBe('cancelled')
            ->and((float) $order->total_amount)->toBe($this->firstTotal)
            ->and(Payment::where('order_round_id', $round->id)->value('payment_status'))->toBe('refunded')
            ->and($order->items)->toHaveCount(2);
    });

    it('leaves the order owing less when the round was Pay later and unpaid (case 2)', function () {
        $round = rcAddWater('pay_later');
        expect($this->order->fresh()->payment_state)->toBe('part_paid');

        rcAsk($this->kofi, $this->order, $round)->assertOk();
        rcApprove($this->admin, $this->order, $round)
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'paid');

        $order = $this->order->fresh();
        expect($order->owing_since)->toBeNull()
            ->and((float) $order->total_amount)->toBe($this->firstTotal);
    });

    it('owes the money back when it was paid inside a bigger payment, and the cashier who hands it over is held to it (case 3)', function () {
        $round = rcAddWater('pay_later');
        $kofiShift = Shift::create([
            'employee_id' => $this->kofiEmployee->id,
            'branch_id' => $this->branch->id,
            'login_at' => now()->subHour(),
            'total_sales' => 0,
            'order_count' => 0,
        ]);

        // The table pays what is owed at the end, in cash, to Kofi.
        $this->actingAs($this->kofi)
            ->postJson("/v1/pos/orders/{$this->order->id}/payments", ['method' => 'cash', 'handed_over' => $this->waterPrice])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/v1/admin/orders/{$this->order->id}/rounds/{$round->id}/cancel", ['reason' => 'Never brought'])
            ->assertOk();

        $order = $this->order->fresh();
        expect($order->to_give_back)->toBe($this->waterPrice)
            ->and($order->payment_state)->toBe('paid');

        $this->actingAs($this->kofi)
            ->postJson("/v1/pos/orders/{$this->order->id}/give-back")
            ->assertCreated()
            ->assertJsonPath('data.order.to_give_back', 0);

        $back = Payment::where('order_id', $this->order->id)->where('source', 'cash_back')->sole();
        expect((float) $back->amount)->toBe(-$this->waterPrice)
            ->and($back->collected_by)->toBe($this->kofiEmployee->id)
            ->and($back->shift_id)->toBe($kofiShift->id)
            // Kofi took the water's money and handed it back: his drawer is level.
            ->and(app(ShiftMoney::class)->for($kofiShift)['cash']['amount'])->toBe(0.0);

        // Nothing is left to hand back, so a second press does nothing.
        $this->actingAs($this->kofi)->postJson("/v1/pos/orders/{$this->order->id}/give-back")->assertStatus(409);
    });

    it('makes the next receipt an Updated Receipt when the customer\'s paper listed it', function () {
        $round = rcAddWater();
        $this->actingAs($this->rosina)->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['source' => 'pos_orders'])->assertOk();
        expect($round->fresh()->receipt_printed_at)->not->toBeNull();

        rcAsk($this->kofi, $this->order, $round)->assertOk();
        rcApprove($this->admin, $this->order, $round)->assertOk();
        expect($round->fresh()->receipt_printed_at)->toBeNull();

        $this->actingAs($this->rosina)->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['source' => 'pos_orders'])->assertOk();
        expect($this->order->receiptPrints()->reorder()->latest('id')->first()->kind)->toBe('updated');
    });

    it('is left out of the dishes sold', function () {
        $round = rcAddWater();
        expect(OrderItem::leaveOutCancelledRounds(OrderItem::where('order_id', $this->order->id))->count())->toBe(2);

        rcAsk($this->kofi, $this->order, $round)->assertOk();
        rcApprove($this->admin, $this->order, $round)->assertOk();

        expect(OrderItem::leaveOutCancelledRounds(OrderItem::where('order_id', $this->order->id))->count())->toBe(1);
    });

    it('is refused while the whole order waits on a cancel, and cannot be cancelled twice', function () {
        $round = rcAddWater();
        $this->actingAs($this->admin)
            ->postJson("/v1/admin/orders/{$this->order->id}/rounds/{$round->id}/cancel", ['reason' => 'Wrong table'])
            ->assertOk();
        $this->actingAs($this->admin)
            ->postJson("/v1/admin/orders/{$this->order->id}/rounds/{$round->id}/cancel", ['reason' => 'Wrong table'])
            ->assertStatus(422);

        $other = rcAddWater();
        $this->order->update(['status' => 'cancel_requested']);
        rcAsk($this->kofi, $this->order, $other)->assertStatus(422);
    });
});
