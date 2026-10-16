<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payments\PaymentCollector;
use App\Services\Payments\TakePayment;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| The money by method, as the sales staff screens show it
|--------------------------------------------------------------------------
|
| USSD apart from MoMo, the food given free on a person's own orders, and the
| money a person took on somebody else's order. Plan:
| docs/STAFF_PORTAL_BUILD.md in the frontend, Section 7, Money.
|
*/

function mmStaff(Branch $branch, string $name): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([RoleEnum::SalesStaff->value]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/** An order of ₵100 of food, no delivery, placed by $owner. */
function mmOrder(Branch $branch, ?Employee $owner, array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'assigned_employee_id' => $owner?->id,
        'order_type' => 'pickup',
        'order_source' => 'pos',
        'subtotal' => 100,
        'delivery_fee' => 0,
        'service_charge' => 0,
        'total_amount' => 100,
        'status' => 'preparing',
    ], $attrs));
}

function mmPay(Order $order, Employee $by, string $method, float $amount, array $attrs = []): Payment
{
    return Payment::create(array_merge([
        'order_id' => $order->id,
        'payment_method' => $method,
        'payment_status' => 'completed',
        'amount' => $amount,
        'collected_by' => $by->id,
        'paid_at' => now(),
    ], $attrs));
}

function mmIncoming(Branch $branch, float $amount): HubtelIncomingPayment
{
    static $n = 0;
    $n++;

    return HubtelIncomingPayment::create([
        'branch_id' => $branch->id,
        'account_number' => '2040195',
        'client_reference' => "NHmm{$n}_233247879103_1",
        'network_transaction_id' => "7100000000{$n}",
        'payer_number' => '233247879103',
        'amount' => $amount,
        'paid_at' => now(),
        'verified_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create(['name' => 'Lakeside', 'is_active' => true]);
    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = mmStaff($this->branch, 'Rosina Mensah');
    ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = mmStaff($this->branch, 'Kofi Asante');
});

describe('USSD apart from MoMo', function () {
    it('counts a payment made by dialling the branch code as USSD, not MoMo', function () {
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        app(TakePayment::class)->branchCode($order, $this->rosinaEmployee, mmIncoming($this->branch, 30)->id);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.money.ussd.amount', 30)
            ->assertJsonPath('data.money.ussd.count', 1)
            ->assertJsonPath('data.money.momo.amount', 0)
            ->assertJsonPath('data.money.momo.count', 0);
    });

    it('keeps a prompt from the till in MoMo', function () {
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        mmPay($order, $this->rosinaEmployee, 'mobile_money', 20, ['source' => PaymentCollector::SOURCE_TILL]);
        // From before the source column, which only a prompt can be now.
        mmPay($order, $this->rosinaEmployee, 'mobile_money', 15, ['source' => null]);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.money.momo.amount', 35)
            ->assertJsonPath('data.money.momo.count', 2)
            ->assertJsonPath('data.money.ussd.amount', 0);
    });

    it('splits them on a shift as well', function () {
        $shift = Shift::create([
            'employee_id' => $this->rosinaEmployee->id,
            'branch_id' => $this->branch->id,
            'login_at' => now()->subHour(),
            'total_sales' => 0,
            'order_count' => 0,
        ]);
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        app(TakePayment::class)->branchCode($order, $this->rosinaEmployee, mmIncoming($this->branch, 30)->id);
        mmPay($order, $this->rosinaEmployee, 'mobile_money', 20, ['shift_id' => $shift->id, 'source' => PaymentCollector::SOURCE_TILL]);

        $this->actingAs($this->rosina)->getJson("/v1/shifts/{$shift->id}/money")
            ->assertOk()
            ->assertJsonPath('data.money.ussd.amount', 30)
            ->assertJsonPath('data.money.momo.amount', 20);
    });

    it('stamps the source on a branch code payment linked before the column existed', function () {
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        $linked = mmPay($order, $this->rosinaEmployee, 'mobile_money', 30, ['source' => null]);
        mmIncoming($this->branch, 30)->forceFill(['payment_id' => $linked->id])->save();
        $prompt = mmPay($order, $this->rosinaEmployee, 'mobile_money', 20, ['source' => null]);

        (require database_path('migrations/2026_10_16_150000_stamp_source_on_branch_code_payments.php'))->up();

        expect($linked->fresh()->source)->toBe(PaymentCollector::SOURCE_BRANCH_CODE_LINK)
            ->and($prompt->fresh()->source)->toBeNull();
    });

    it('says on each MoMo payment of an order whether it was a prompt or USSD', function () {
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        mmPay($order, $this->rosinaEmployee, 'cash', 10);
        mmPay($order, $this->rosinaEmployee, 'mobile_money', 20, ['source' => PaymentCollector::SOURCE_TILL]);
        app(TakePayment::class)->branchCode($order, $this->rosinaEmployee, mmIncoming($this->branch, 30)->id);

        $payments = collect(OrderResource::make($order->fresh())->resolve()['payments'])->keyBy('amount');

        expect($payments[10.0]['via'])->toBeNull()
            ->and($payments[20.0]['via'])->toBe('prompt')
            ->and($payments[30.0]['via'])->toBe('ussd');
    });
});

describe('money taken on someone else\'s order', function () {
    it('shows in the list of the one who took it, and not in the list of the order\'s owner', function () {
        $order = mmOrder($this->branch, $this->kofiEmployee);
        mmPay($order, $this->rosinaEmployee, 'cash', 50);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.money.cash.amount', 50)
            ->assertJsonCount(1, 'data.on_others_orders')
            ->assertJsonPath('data.on_others_orders.0.order_number', $order->order_number)
            ->assertJsonPath('data.on_others_orders.0.assigned_to', 'Kofi')
            ->assertJsonPath('data.on_others_orders.0.group', 'cash')
            ->assertJsonPath('data.on_others_orders.0.amount', 50);

        $this->actingAs($this->kofi)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.money.cash.amount', 0)
            ->assertJsonCount(0, 'data.on_others_orders');
    });

    it('leaves out money taken on the person\'s own order', function () {
        $order = mmOrder($this->branch, $this->rosinaEmployee);
        mmPay($order, $this->rosinaEmployee, 'cash', 40);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.money.cash.amount', 40)
            ->assertJsonCount(0, 'data.on_others_orders');
    });

    it('gives one line for each method on the same order, and leaves out yesterday', function () {
        $order = mmOrder($this->branch, $this->kofiEmployee);
        mmPay($order, $this->rosinaEmployee, 'cash', 20);
        app(TakePayment::class)->branchCode($order, $this->rosinaEmployee, mmIncoming($this->branch, 30)->id);
        mmPay(mmOrder($this->branch, $this->kofiEmployee), $this->rosinaEmployee, 'cash', 99, ['paid_at' => now()->subDay()]);

        $lines = collect($this->actingAs($this->rosina)->getJson('/v1/my-money')->assertOk()->json('data.on_others_orders'));

        expect($lines)->toHaveCount(2)
            ->and($lines->firstWhere('group', 'cash')['amount'])->toEqual(20)
            ->and($lines->firstWhere('group', 'ussd')['amount'])->toEqual(30);
    });

    it('counts an order nobody holds as someone else\'s, with no name', function () {
        $order = mmOrder($this->branch, null);
        mmPay($order, $this->rosinaEmployee, 'cash', 25);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.on_others_orders.0.assigned_to', null)
            ->assertJsonPath('data.on_others_orders.0.amount', 25);
    });
});

describe('No Charge', function () {
    it('adds up the food given free on the person\'s own orders today, and no money', function () {
        $free = fn (?Employee $owner, array $attrs = []) => tap(mmOrder($this->branch, $owner, $attrs), fn (Order $o) => Payment::create([
            'order_id' => $o->id,
            'payment_method' => 'no_charge',
            'payment_status' => 'no_charge',
            'amount' => 0,
            'collected_by' => $owner?->id,
        ]));

        $free($this->rosinaEmployee, ['total_amount' => 80, 'subtotal' => 80]);
        // Delivery is never the restaurant's, so only the food counts.
        $free($this->rosinaEmployee, ['total_amount' => 70, 'subtotal' => 50, 'delivery_fee' => 20]);
        $free($this->kofiEmployee);
        $free($this->rosinaEmployee, ['status' => 'cancelled']);
        $free($this->rosinaEmployee, ['created_at' => now()->subDay()]);

        $this->actingAs($this->rosina)->getJson('/v1/my-money')
            ->assertOk()
            ->assertJsonPath('data.no_charge.amount', 130)
            ->assertJsonPath('data.no_charge.count', 2)
            ->assertJsonPath('data.money.cash.amount', 0)
            ->assertJsonPath('data.money.other.amount', 0);
    });
});
