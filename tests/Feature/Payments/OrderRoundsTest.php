<?php

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderRound;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Adding items to an order (Phase 4b, D29 to D33)
|--------------------------------------------------------------------------
|
| A table orders, eats, then wants a bottle of water. The water is a round
| inside the same order: one order number, one total, many payments. It goes
| to the kitchen as its own ticket and takes its own stock.
|
*/

function rdStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function rdDish(Branch $branch, string $name = 'Jollof'): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

function rdBody(Branch $branch, MenuItem $dish, array $overrides = []): array
{
    return array_merge([
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
}

/** Ring a sale and confirm it in cash, as the till does. */
function rdRing(User $cashier, array $body)
{
    $session = test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', $body);

    if ($session->status() !== 201 || $session->json('status') === 'confirmed' || $body['payment_method'] !== 'cash') {
        return $session;
    }

    return test()->actingAs($cashier)->postJson(
        '/v1/pos/checkout-sessions/'.$session->json('session_token').'/confirm-cash',
        ['amount_paid' => 500],
    );
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create([
        'name' => 'Lakeside',
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = rdStaff($this->branch, 'Rosina');
    ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = rdStaff($this->branch, 'Kofi');

    // Ama's table: jollof, paid in cash by Rosina.
    $this->jollof = rdDish($this->branch);
    $this->water = rdDish($this->branch, 'Water');
    $this->order = Order::findOrFail(rdRing($this->rosina, rdBody($this->branch, $this->jollof))->assertCreated()->json('order.id'));
    $this->firstTotal = (float) $this->order->total_amount;
    $this->waterPrice = (float) $this->water->options->first()->price;
});

describe('adding a round', function () {
    it('adds paid items to the same order, as a round with its own payment', function () {
        $response = rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))
            ->assertCreated()
            ->assertJsonPath('order.id', $this->order->id)
            ->assertJsonPath('order.order_number', $this->order->order_number)
            ->assertJsonPath('order.payment_state', 'paid')
            ->assertJsonPath('order.rounds.0.number', 2)
            ->assertJsonPath('order.rounds.0.added_by_name', 'Kofi');

        $order = $this->order->fresh(['rounds', 'items', 'payments']);
        $round = $order->rounds->sole();

        expect(Order::count())->toBe(1)
            ->and((float) $order->total_amount)->toBe(round($this->firstTotal + $this->waterPrice, 2))
            ->and($order->items)->toHaveCount(2)
            ->and($order->items->whereNull('order_round_id'))->toHaveCount(1)
            ->and($order->items->where('order_round_id', $round->id))->toHaveCount(1)
            ->and($round->added_by)->toBe($this->kofiEmployee->id)
            ->and($round->paid_later)->toBeFalse()
            // The sale stays Rosina's; the round is Kofi's.
            ->and($order->assigned_employee_id)->toBe($this->rosinaEmployee->id);

        $roundPayment = $order->payments->firstWhere('order_round_id', $round->id);
        expect($roundPayment)->not->toBeNull()
            ->and((float) $roundPayment->amount)->toBe($this->waterPrice)
            ->and($roundPayment->collected_by)->toBe($this->kofiEmployee->id)
            ->and((float) $roundPayment->amount_tendered)->toBe(500.0);

        // Each item says which round it came in.
        $rounds = collect($response->json('order.items'))->pluck('round')->sort()->values()->all();
        expect($rounds)->toBe([1, 2]);
    });

    it('makes a paid order owe again when the round is placed with Pay later', function () {
        $this->order->forceFill(['unpaid_alerted_at' => now()->subHours(3)])->saveQuietly();

        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'payment_method' => 'pay_later']))
            ->assertCreated()
            ->assertJsonPath('order.payment_state', 'part_paid')
            ->assertJsonPath('order.rounds.0.paid_later', true);

        $order = $this->order->fresh(['payments']);
        expect($order->paid_later)->toBeTrue()
            ->and($order->owing_since)->not->toBeNull()
            ->and($order->unpaid_alerted_at)->toBeNull()
            ->and($order->balance_due)->toBe($this->waterPrice);

        // It is on the till's Not paid list.
        $ids = collect($this->actingAs($this->kofi)
            ->getJson('/v1/employee/orders?owing=1&branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data.data'))->pluck('id');
        expect($ids->all())->toBe([$this->order->id]);
    });

    it('stops owing once the round is paid, and leaves the Not paid list', function () {
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'payment_method' => 'pay_later']))
            ->assertCreated();

        $this->actingAs($this->kofi)
            ->postJson("/v1/pos/orders/{$this->order->id}/payments", ['method' => 'cash', 'handed_over' => $this->waterPrice])
            ->assertCreated()
            ->assertJsonPath('data.order.payment_state', 'paid');

        expect($this->order->fresh()->owing_since)->toBeNull();

        $ids = collect($this->actingAs($this->kofi)
            ->getJson('/v1/employee/orders?owing=1&branch_id='.$this->branch->id)
            ->json('data.data'))->pluck('id');
        expect($ids->all())->toBe([]);
    });

    it('takes no promo on a round, and does not refuse it for a code', function () {
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'promo_code' => 'NOSUCHCODE']))
            ->assertCreated();

        expect((float) $this->order->rounds()->sole()->total)->toBe($this->waterPrice);
    });

    it('takes the round\'s stock once, paid now or not', function () {
        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('deductForOrder')->andReturnNull();
        $stock->shouldReceive('deductForRound')->once();

        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertCreated();
    });

    it('waits for the money on a MoMo prompt round, then adds it', function () {
        config()->set('services.hubtel.rmp_client_id', 'company-id');
        config()->set('services.hubtel.rmp_client_secret', 'company-secret');
        config()->set('services.hubtel.merchant_account_number', '2038092');
        Http::fake(['rmp.hubtel.com/*' => Http::response([
            'ResponseCode' => '0001',
            'Message' => 'Transaction pending. Expect callback request for final state',
            'Data' => ['TransactionId' => 'hub-round-1', 'ClientReference' => 'x'],
        ])]);

        $token = rdRing($this->kofi, rdBody($this->branch, $this->water, [
            'add_to_order_id' => $this->order->id,
            'payment_method' => 'mobile_money',
            'momo_number' => '0247879103',
        ]))->assertCreated()->json('session_token');

        expect($this->order->rounds()->count())->toBe(0);

        $this->postJson('/v1/payments/hubtel/rmp/callback', [
            'ResponseCode' => '0000',
            'Message' => 'success',
            'Data' => [
                'ClientReference' => $token,
                'TransactionId' => 'hub-round-1',
                'ExternalTransactionId' => 'mtn-round-1',
                'Amount' => $this->waterPrice,
            ],
        ])->assertOk();

        $order = $this->order->fresh(['rounds', 'payments']);
        expect(Order::count())->toBe(1)
            ->and($order->rounds)->toHaveCount(1)
            ->and($order->payment_state)->toBe('paid')
            ->and($order->payments->where('payment_method', 'mobile_money')->sole()->order_round_id)->toBe($order->rounds->sole()->id);
    });

    it('numbers each round in turn', function () {
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertCreated();
        rdRing($this->rosina, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertCreated();

        expect($this->order->rounds()->pluck('number')->all())->toBe([2, 3]);
    });
});

describe('what cannot take a round', function () {
    it('refuses a takeaway order', function () {
        $takeaway = Order::findOrFail(rdRing($this->rosina, rdBody($this->branch, $this->jollof, ['fulfillment_type' => 'takeaway']))->json('order.id'));

        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $takeaway->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_add_to_order');
    });

    it('refuses yesterday\'s order', function () {
        $this->order->forceFill(['created_at' => now()->subDay()])->saveQuietly();

        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertStatus(422);
    });

    it('refuses a cancelled order, and one waiting on a cancel', function () {
        $this->order->forceFill(['status' => 'cancel_requested'])->saveQuietly();
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertStatus(422);

        $this->order->forceFill(['status' => 'cancelled'])->saveQuietly();
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertStatus(422);
    });

    it('refuses another branch\'s order', function () {
        $elsewhere = Branch::factory()->create(['is_active' => true, 'extended_staff_access' => true, 'extended_order_access' => true]);
        ['user' => $yaw] = rdStaff($elsewhere, 'Yaw');
        $theirs = Order::findOrFail(rdRing($yaw, rdBody($elsewhere, rdDish($elsewhere)))->json('order.id'));

        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $theirs->id]))->assertStatus(422);
    });

    it('refuses no charge for a round', function () {
        $admin = rdStaff($this->branch, 'Admin', RoleEnum::Admin->value)['user'];

        rdRing($admin, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'payment_method' => 'no_charge']))
            ->assertStatus(422);

        expect(OrderRound::count())->toBe(0);
    });
});

describe('the kitchen', function () {
    beforeEach(function () {
        // Ama's first order is served and done.
        $this->order->forceFill(['status' => 'completed'])->saveQuietly();
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertCreated();
        $this->round = $this->order->rounds()->sole();
    });

    it('brings a served order back to the boards while its round is live', function () {
        expect($this->round->status)->toBe('accepted');

        $board = collect($this->actingAs($this->rosina)
            ->getJson('/v1/order-manager/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'));
        expect($board->pluck('id')->all())->toContain($this->order->id)
            ->and($board->firstWhere('id', $this->order->id)['rounds'][0]['status'])->toBe('accepted');

        ['user' => $cook] = rdStaff($this->branch, 'Kojo', RoleEnum::Kitchen->value);
        $kitchen = collect($this->actingAs($cook)->getJson('/v1/kitchen/orders?branch_id='.$this->branch->id)->assertOk()->json('data'));
        expect($kitchen->pluck('id')->all())->toContain($this->order->id);

        // The Order Manager's own list asks by status.
        $listed = collect($this->actingAs($this->rosina)
            ->getJson('/v1/employee/orders?branch_id='.$this->branch->id.'&status[]=accepted&status[]=preparing')
            ->json('data.data'))->pluck('id');
        expect($listed->all())->toContain($this->order->id);
    });

    it('moves a round on its own, and leaves the order\'s status alone', function () {
        $this->actingAs($this->rosina)
            ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$this->round->id}/status", ['status' => 'preparing'])
            ->assertOk()
            ->assertJsonPath('data.rounds.0.status', 'preparing')
            ->assertJsonPath('data.status', 'completed');

        // A second tap from another screen does nothing.
        $this->actingAs($this->rosina)
            ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$this->round->id}/status", ['status' => 'preparing'])
            ->assertOk();

        // It cannot jump back.
        $this->actingAs($this->rosina)
            ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$this->round->id}/status", ['status' => 'accepted'])
            ->assertStatus(422);
    });

    it('drops the order off the boards once its round is done', function () {
        foreach (['preparing', 'ready', 'completed'] as $status) {
            $this->actingAs($this->rosina)
                ->patchJson("/v1/employee/orders/{$this->order->id}/rounds/{$this->round->id}/status", ['status' => $status])
                ->assertOk();
        }

        $board = collect($this->actingAs($this->rosina)
            ->getJson('/v1/order-manager/orders?branch_id='.$this->branch->id)
            ->json('data'))->pluck('id');
        expect($board->all())->not->toContain($this->order->id);
    });

    it('refuses a round that is not on the order', function () {
        $other = Order::findOrFail(rdRing($this->rosina, rdBody($this->branch, $this->jollof))->json('order.id'));

        $this->actingAs($this->rosina)
            ->patchJson("/v1/employee/orders/{$other->id}/rounds/{$this->round->id}/status", ['status' => 'preparing'])
            ->assertNotFound();
    });
});

describe('paper', function () {
    it('prints the next receipt as an Updated Receipt once a round is added, then reprints', function () {
        $print = fn () => $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['source' => 'order_manager'])
            ->assertOk();

        $print();
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id]))->assertCreated();
        $print();
        $print();

        $kinds = $this->order->receiptPrints()->reorder('id')->get(['kind', 'copies', 'reprint_number']);
        expect($kinds->pluck('kind')->all())->toBe(['original', 'updated', 'reprint'])
            ->and($kinds[1]->copies)->toBe(2)
            ->and($this->order->rounds()->sole()->receipt_printed_at)->not->toBeNull();
    });

    it('logs a bill for an order that owes, and leaves the receipt count alone', function () {
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'payment_method' => 'pay_later']))
            ->assertCreated();

        $this->actingAs($this->kofi)
            ->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['slip' => 'bill', 'source' => 'pos_orders'])
            ->assertOk();

        expect($this->order->receiptPrints()->sole()->kind)->toBe('bill')
            ->and($this->order->fresh()->receipt_print_count)->toBe(0);
    });

    it('refuses a bill when nothing is owed', function () {
        $this->actingAs($this->kofi)
            ->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['slip' => 'bill'])
            ->assertStatus(422);
    });

    it('logs a round\'s kitchen slip against the round', function () {
        rdRing($this->kofi, rdBody($this->branch, $this->water, ['add_to_order_id' => $this->order->id, 'payment_method' => 'pay_later']))
            ->assertCreated();

        $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$this->order->id}/receipt-printed", ['slip' => 'kitchen', 'round' => 2, 'source' => 'order_manager'])
            ->assertOk()
            ->assertJsonPath('data.rounds.0.kitchen_slip_printed_at', fn ($at) => $at !== null);

        $order = $this->order->fresh();
        expect($order->kitchen_slip_printed_at)->toBeNull()
            ->and($order->receipt_print_count)->toBe(0);
    });
});
