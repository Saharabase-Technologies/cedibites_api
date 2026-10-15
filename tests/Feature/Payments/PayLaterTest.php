<?php

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Pay later: placed now, to the kitchen now, paid afterwards (D1 to D7)
|--------------------------------------------------------------------------
|
| An order has a total and a list of payments. It is paid when the payments
| cover the total. A Pay later order starts with none.
|
*/

function oweStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function oweDish(Branch $branch): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Jollof']);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

/** Rosina taps Pay later for Ama's order. */
function owePlace(User $cashier, Branch $branch, array $overrides = [])
{
    $dish = oweDish($branch);

    return test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', array_merge([
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 90,
        ]],
        'payment_method' => 'pay_later',
        'fulfillment_type' => 'dine_in',
        'contact_name' => 'Ama',
        'contact_phone' => '+233247879103',
    ], $overrides));
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    // Kept open, so the sale is not refused by the time of day.
    $this->branch = Branch::factory()->create([
        'name' => 'Lakeside',
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = oweStaff($this->branch, 'Rosina');
});

describe('placing a Pay later order', function () {
    it('makes the order at once, with nothing paid', function () {
        $response = owePlace($this->rosina, $this->branch)
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('order.paid_later', true)
            ->assertJsonPath('order.payment_state', 'not_paid')
            ->assertJsonPath('order.amount_paid', 0);

        $order = Order::findOrFail($response->json('order.id'));

        // The whole of the goods is owed. The till charges the menu's price.
        expect($response->json('order.balance_due'))->toBe($order->goods_amount)
            ->and($order->goods_amount)->toBeGreaterThan(0.0)
            ->and($order->paid_later)->toBeTrue()
            ->and($order->placed_unpaid_at)->not->toBeNull()
            ->and($order->fully_paid_at)->toBeNull()
            ->and($order->payments()->count())->toBe(0)
            ->and($order->assigned_employee_id)->toBe($this->rosinaEmployee->id);
    });

    it('runs the same checks as any sale before it makes anything', function () {
        owePlace($this->rosina, $this->branch, ['promo_code' => 'NOSUCHCODE'])->assertStatus(422);

        $elsewhere = Branch::factory()->create(['is_active' => true, 'extended_staff_access' => true, 'extended_order_access' => true]);
        owePlace($this->rosina, $elsewhere)->assertForbidden();

        expect(Order::count())->toBe(0);
    });

    it('is not offered for a recorded past order, which was paid at the time', function () {
        owePlace($this->rosina, $this->branch, [
            'is_manual_entry' => true,
            'recorded_at' => now()->subHour()->toIso8601String(),
            'manual_entry_reason' => 'Power cut',
        ])->assertStatus(422);

        expect(Order::count())->toBe(0);
    });

    it('takes the stock when the order is placed, since the kitchen cooks it now', function () {
        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('deductForOrder')->once();

        owePlace($this->rosina, $this->branch)->assertCreated();
    });

    it('gives the stock back when an unpaid Pay later order is cancelled', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('reverseForOrder')->once();

        $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    });
});

describe('the kitchen and the Order Manager', function () {
    beforeEach(function () {
        $this->payLater = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));

        // An online order whose MoMo prompt is still waiting. Not paid, not vouched for.
        $this->waiting = Order::factory()->create(['branch_id' => $this->branch->id, 'status' => 'received', 'order_source' => 'online']);
        Payment::create([
            'order_id' => $this->waiting->id,
            'payment_method' => 'mobile_money',
            'payment_status' => 'pending',
            'amount' => $this->waiting->goods_amount,
        ]);
    });

    it('shows a Pay later order on the Order Manager, and not an unpaid online one', function () {
        $ids = collect($this->actingAs($this->rosina)
            ->getJson('/v1/order-manager/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        expect($ids)->toContain($this->payLater->id)
            ->and($ids)->not->toContain($this->waiting->id);
    });

    it('shows a Pay later order on the kitchen display, and not an unpaid online one', function () {
        ['user' => $cook] = oweStaff($this->branch, 'Kojo', RoleEnum::Kitchen->value);

        $ids = collect($this->actingAs($cook)
            ->getJson('/v1/kitchen/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        expect($ids)->toContain($this->payLater->id)
            ->and($ids)->not->toContain($this->waiting->id);
    });
});

/*
|--------------------------------------------------------------------------
| Taking payment on an order that owes (D7)
|--------------------------------------------------------------------------
*/

function oweTake(User $cashier, Order $order, array $body)
{
    return test()->actingAs($cashier)->postJson("/v1/pos/orders/{$order->id}/payments", $body);
}

function oweIncoming(array $attrs = []): HubtelIncomingPayment
{
    static $n = 0;
    $n++;

    return HubtelIncomingPayment::create(array_merge([
        'branch_id' => null,
        'account_number' => '2040195',
        'client_reference' => "NHowe{$n}_233247879103_1",
        'network_transaction_id' => "7000000000{$n}",
        'payer_number' => '233247879103',
        'amount' => 10,
        'paid_at' => now(),
        'verified_at' => now(),
    ], $attrs));
}

function oweHubtelPrompt(): void
{
    config()->set('services.hubtel.rmp_client_id', 'company-id');
    config()->set('services.hubtel.rmp_client_secret', 'company-secret');
    config()->set('services.hubtel.payment_client_id', 'company-id');
    config()->set('services.hubtel.payment_client_secret', 'company-secret');
    config()->set('services.hubtel.merchant_account_number', '2038092');

    // A transaction ID of its own for every prompt, as Hubtel gives.
    Http::fake([
        'rmp.hubtel.com/*' => fn () => Http::response([
            'ResponseCode' => '0001',
            'Message' => 'Transaction pending. Expect callback request for final state',
            'Data' => ['TransactionId' => 'hub-tx-'.uniqid('', true), 'ClientReference' => 'x'],
        ]),
    ]);
}

function oweCallback(string $reference, string $code = '0000')
{
    return test()->postJson('/v1/payments/hubtel/rmp/callback', [
        'ResponseCode' => $code,
        'Message' => $code === '0000' ? 'success' : 'failed',
        'Data' => [
            'ClientReference' => $reference,
            'TransactionId' => 'hub-'.$reference,
            'ExternalTransactionId' => 'mtn-'.$reference,
            'Amount' => 1,
        ],
    ]);
}

describe('taking payment', function () {
    beforeEach(function () {
        $this->order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        $this->owed = $this->order->goods_amount;

        // Kofi is on the till by the time Ama pays.
        ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = oweStaff($this->branch, 'Kofi');
        $this->kofiShift = Shift::create([
            'employee_id' => $this->kofiEmployee->id,
            'branch_id' => $this->branch->id,
            'login_at' => now()->subHour(),
            'total_sales' => 0,
            'order_count' => 0,
        ]);
    });

    it('takes part in cash, against whoever took it, and leaves the sale with whoever placed it', function () {
        oweTake($this->kofi, $this->order, ['method' => 'cash', 'handed_over' => 10])
            ->assertCreated()
            ->assertJsonPath('data.change', 0)
            ->assertJsonPath('data.order.payment_state', 'part_paid')
            ->assertJsonPath('data.order.amount_paid', 10);

        $payment = $this->order->payments()->sole();
        expect($payment->collected_by)->toBe($this->kofiEmployee->id)
            ->and($payment->shift_id)->toBe($this->kofiShift->id)
            ->and($payment->source)->toBe('pay_later')
            ->and($this->order->fresh()->assigned_employee_id)->toBe($this->rosinaEmployee->id)
            ->and($this->order->fresh()->fully_paid_at)->toBeNull();
    });

    it('gives change when more cash is handed over than is owed, and marks the order paid', function () {
        $response = oweTake($this->kofi, $this->order, ['method' => 'cash', 'handed_over' => $this->owed + 5])
            ->assertCreated()
            ->assertJsonPath('data.order.payment_state', 'paid');

        $payment = $this->order->payments()->sole();
        expect((float) $response->json('data.change'))->toBe(5.0)
            ->and((float) $payment->amount)->toBe($this->owed)
            ->and((float) $payment->amount_tendered)->toBe(round($this->owed + 5, 2))
            ->and($this->order->fresh()->fully_paid_at)->not->toBeNull();
    });

    it('refuses a card part over what is owed, and takes one that fits', function () {
        oweTake($this->kofi, $this->order, ['method' => 'card', 'amount' => $this->owed + 1])->assertStatus(422);

        oweTake($this->kofi, $this->order, ['method' => 'card', 'amount' => $this->owed])
            ->assertCreated()
            ->assertJsonPath('data.order.payment_state', 'paid');
    });

    it('takes nothing on an order already paid', function () {
        oweTake($this->kofi, $this->order, ['method' => 'cash', 'handed_over' => $this->owed])->assertCreated();

        oweTake($this->kofi, $this->order, ['method' => 'cash', 'handed_over' => 5])->assertStatus(409);
    });

    it('takes nothing on a cancelled order', function () {
        $this->order->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        oweTake($this->kofi, $this->order, ['method' => 'cash', 'handed_over' => 5])->assertStatus(409);
    });

    it('does not let another branch\'s cashier take money for the order', function () {
        $elsewhere = Branch::factory()->create(['is_active' => true]);
        ['user' => $stranger] = oweStaff($elsewhere, 'Yaw');

        oweTake($stranger, $this->order, ['method' => 'cash', 'handed_over' => 5])->assertForbidden();
    });

    it('links a branch code payment, which becomes this branch\'s and is linked once', function () {
        $incoming = oweIncoming(['amount' => 10]);

        oweTake($this->kofi, $this->order, ['method' => 'branch_code', 'incoming_payment_id' => $incoming->id])
            ->assertCreated()
            ->assertJsonPath('data.order.amount_paid', 10);

        $payment = $this->order->payments()->sole();
        $incoming->refresh();
        expect($payment->payment_method)->toBe('mobile_money')
            ->and($payment->source)->toBe('branch_code_link')
            ->and($payment->transaction_id)->toBe($incoming->network_transaction_id)
            ->and($incoming->payment_id)->toBe($payment->id)
            ->and($incoming->branch_id)->toBe($this->branch->id)
            ->and($incoming->taken_by)->toBe($this->kofiEmployee->id);

        oweTake($this->kofi, $this->order, ['method' => 'branch_code', 'incoming_payment_id' => $incoming->id])
            ->assertStatus(409);
    });

    it('refuses a branch code payment on another branch\'s till, or worth more than is owed', function () {
        $ashaiman = Branch::factory()->create(['name' => 'Ashaiman', 'is_active' => true]);

        oweTake($this->kofi, $this->order, ['method' => 'branch_code', 'incoming_payment_id' => oweIncoming(['branch_id' => $ashaiman->id])->id])
            ->assertStatus(409)
            ->assertJsonPath('message', "That payment is on Ashaiman's till.");

        oweTake($this->kofi, $this->order, ['method' => 'branch_code', 'incoming_payment_id' => oweIncoming(['amount' => $this->owed + 50])->id])
            ->assertStatus(422);

        expect($this->order->payments()->count())->toBe(0);
    });

    it('sends a MoMo prompt for a part, with a reference of its own, and settles it on Hubtel\'s answer', function () {
        oweHubtelPrompt();

        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => 10, 'momo_number' => '0241234567'])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'pending')
            ->assertJsonPath('data.order.payment_state', 'not_paid');

        $reference = 'PL-'.$this->order->order_number.'-1';
        Http::assertSent(fn ($r) => str_contains($r->url(), 'receive/mobilemoney')
            && $r['ClientReference'] === $reference
            && (float) $r['Amount'] === 10.0);

        oweCallback($reference)->assertOk();

        $payment = $this->order->payments()->sole();
        expect($payment->payment_status)->toBe('completed')
            ->and($payment->collected_by)->toBe($this->kofiEmployee->id)
            ->and($this->order->fresh()->payment_state)->toBe('part_paid');
    });

    it('settles the right part when two prompts were sent for one order', function () {
        oweHubtelPrompt();

        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => $this->owed, 'momo_number' => '0241234567'])->assertCreated();
        $first = 'PL-'.$this->order->order_number.'-1';
        oweCallback($first, '2001')->assertOk();

        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => $this->owed, 'momo_number' => '0551234567'])->assertCreated();
        $second = 'PL-'.$this->order->order_number.'-2';
        oweCallback($second)->assertOk();

        expect(Payment::where('reference', $first)->value('payment_status'))->toBe('failed')
            ->and(Payment::where('reference', $second)->value('payment_status'))->toBe('completed')
            ->and($this->order->fresh()->fully_paid_at)->not->toBeNull();
    });

    it('does not send a second prompt for money a waiting prompt already asks for', function () {
        oweHubtelPrompt();

        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => $this->owed, 'momo_number' => '0241234567'])->assertCreated();

        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => 1, 'momo_number' => '0241234567'])->assertStatus(409);
    });

    it('knows a part\'s prompt as our own when Hubtel posts it as a payment notification', function () {
        expect(app(\App\Services\Payments\BranchCodePayments::class)->isOurs('PL-AJ700-1'))->toBeTrue();
    });

    it('asks Hubtel about a prompt whose answer never came', function () {
        oweHubtelPrompt();
        oweTake($this->kofi, $this->order, ['method' => 'mobile_money', 'amount' => 10, 'momo_number' => '0241234567'])->assertCreated();
        $payment = $this->order->payments()->sole();

        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response([
            'responseCode' => '0000',
            'data' => ['status' => 'Paid', 'clientReference' => $payment->reference, 'externalTransactionId' => '555'],
        ])]);

        // Read from our own record, Hubtel not asked: still waiting.
        $this->actingAs($this->kofi)
            ->postJson("/v1/pos/orders/{$this->order->id}/payments/{$payment->id}/check")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'pending');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api-txnstatus'));

        $this->actingAs($this->kofi)
            ->postJson("/v1/pos/orders/{$this->order->id}/payments/{$payment->id}/check", ['ask_hubtel' => true])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'completed');
    });
});

describe('refunding one part', function () {
    it('keeps the stock taken when only one part of a paid order is refunded', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        oweTake($this->rosina, $order, ['method' => 'cash', 'handed_over' => 5])->assertCreated();
        oweTake($this->rosina, $order, ['method' => 'card', 'amount' => round($order->goods_amount - 5, 2)])->assertCreated();

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldNotReceive('reverseForOrder');

        $order->payments()->where('payment_method', 'cash')->first()->update(['payment_status' => 'refunded', 'refunded_at' => now()]);
    });

    it('gives the stock back when the last paid part is refunded', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        oweTake($this->rosina, $order, ['method' => 'cash', 'handed_over' => $order->goods_amount])->assertCreated();

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('reverseForOrder')->once();

        $order->payments()->sole()->update(['payment_status' => 'refunded', 'refunded_at' => now()]);
    });
});

describe('the kitchen slip', function () {
    it('is logged on its own, so the paid receipt after it is still the original', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));

        $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$order->id}/receipt-printed", ['source' => 'order_manager', 'slip' => 'kitchen'])
            ->assertOk()
            ->assertJsonPath('data.receipt_print_count', 0);

        $order->refresh();
        expect($order->kitchen_slip_printed_at)->not->toBeNull()
            ->and($order->receipt_print_count)->toBe(0)
            ->and($order->receiptPrints()->sole()->kind)->toBe('kitchen');

        oweTake($this->rosina, $order, ['method' => 'cash', 'handed_over' => $order->goods_amount])->assertCreated();

        $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$order->id}/receipt-printed", ['source' => 'pos_orders'])
            ->assertOk();

        expect($order->receiptPrints()->latest('id')->first()->kind)->toBe('original');
    });

    it('is refused for an order that was not placed with Pay later', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$order->id}/receipt-printed", ['slip' => 'kitchen'])
            ->assertStatus(422);
    });
});

describe('the till\'s list of what is owed', function () {
    it('lists every order still owing at the branch, from any day, and nothing paid or cancelled', function () {
        $owing = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        $owing->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $paid = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        oweTake($this->rosina, $paid, ['method' => 'cash', 'handed_over' => $paid->goods_amount])->assertCreated();

        $cancelled = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $ids = collect($this->actingAs($this->rosina)
            ->getJson('/v1/employee/orders?owing=1&branch_id='.$this->branch->id.'&date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->json('data.data'))->pluck('id');

        expect($ids->all())->toBe([$owing->id]);
    });

    it('says which order a linked branch code payment went to', function () {
        $order = Order::findOrFail(owePlace($this->rosina, $this->branch)->json('order.id'));
        $incoming = oweIncoming(['amount' => 5, 'branch_id' => $this->branch->id]);
        oweTake($this->rosina, $order, ['method' => 'branch_code', 'incoming_payment_id' => $incoming->id])->assertCreated();

        $this->actingAs($this->rosina)
            ->getJson('/v1/pos/branch-code-payments?branch_id='.$this->branch->id)
            ->assertOk()
            ->assertJsonPath('data.0.linked_order_number', $order->order_number);
    });
});
