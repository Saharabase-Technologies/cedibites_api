<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderCreationService;
use App\Services\Payments\ShiftMoney;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Every payment records who took it and on which shift (D8, D9)
|--------------------------------------------------------------------------
|
| The sale belongs to whoever placed the order. The money belongs to whoever
| took it. A shift adds up only its own person's payments, on the server,
| not from what the till's browser remembered to send (T12).
|
*/

function mfStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function mfShift(Employee $employee, Branch $branch, array $attrs = []): Shift
{
    return Shift::create(array_merge([
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'login_at' => now()->subHours(2),
        'logout_at' => null,
        'total_sales' => 0,
        'order_count' => 0,
    ], $attrs));
}

function mfDish(Branch $branch): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Jollof']);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    return $dish->fresh(['options']);
}

/** A sale rung at the till and paid there and then, by cash or card. */
function mfRing(User $cashier, Branch $branch, string $method = 'cash'): Payment
{
    $dish = mfDish($branch);

    test()->actingAs($cashier)
        ->postJson('/v1/pos/checkout-sessions', [
            'branch_id' => $branch->id,
            'items' => [[
                'menu_item_id' => $dish->id,
                'menu_item_option_id' => $dish->options->first()->id,
                'quantity' => 1,
                'unit_price' => 20,
            ]],
            'payment_method' => $method,
            'fulfillment_type' => 'takeaway',
            'contact_name' => 'Ama',
            'contact_phone' => '+233541234567',
        ])
        ->assertSuccessful();

    $token = CheckoutSession::latest('id')->first()->session_token;

    test()->actingAs($cashier)
        ->postJson("/v1/pos/checkout-sessions/{$token}/confirm-{$method}", ['amount_paid' => 20])
        ->assertSuccessful();

    return Payment::latest('id')->first();
}

/** A session as the till or the website leaves it, waiting for Hubtel. */
function mfSession(Branch $branch, array $attrs = []): CheckoutSession
{
    $dish = mfDish($branch);

    return CheckoutSession::create(array_merge([
        'session_token' => (string) Str::uuid(),
        'branch_id' => $branch->id,
        'session_type' => 'pos',
        'status' => 'pending',
        'customer_name' => 'Ama',
        'customer_phone' => '+233541234567',
        'fulfillment_type' => 'takeaway',
        'payment_method' => 'mobile_money',
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 20,
        ]],
        'subtotal' => 20,
        'total_amount' => 20,
        'expires_at' => now()->addMinutes(5),
    ], $attrs));
}

function mfPaid(CheckoutSession $session): Payment
{
    $order = app(OrderCreationService::class)->createFromCheckoutSession($session->fresh());

    return Payment::where('order_id', $order->id)->sole();
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

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = mfStaff($this->branch, 'Rosina');
});

describe('who took it', function () {
    it('records the cashier and their shift on a cash sale', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);

        $payment = mfRing($this->rosina, $this->branch, 'cash');

        expect($payment->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($payment->shift_id)->toBe($shift->id)
            ->and($payment->source)->toBe('till')
            ->and($payment->collector_inferred)->toBeFalse();
    });

    it('records the cashier and their shift on a card sale', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);

        $payment = mfRing($this->rosina, $this->branch, 'card');

        expect($payment->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($payment->shift_id)->toBe($shift->id);
    });

    it('keeps a MoMo prompt paid after the shift ended on the cashier who sent it', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);
        $session = mfSession($this->branch, ['staff_id' => $this->rosinaEmployee->id]);

        // Rosina goes home. Ten minutes later the customer approves the prompt.
        $shift->update(['logout_at' => now()]);
        $this->travel(10)->minutes();
        // Kofi's shift has started at the same till by then.
        ['employee' => $kofi] = mfStaff($this->branch, 'Kofi');
        mfShift($kofi, $this->branch, ['login_at' => now()->subMinutes(5)]);

        $payment = mfPaid($session);

        expect($payment->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($payment->shift_id)->toBe($shift->id);
    });

    it('records the cashier with no shift when they had none open', function () {
        $payment = mfRing($this->rosina, $this->branch, 'cash');

        expect($payment->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($payment->shift_id)->toBeNull();
    });

    it('does not put a sale on the cashier\'s shift at another branch', function () {
        $elsewhere = Branch::factory()->create(['is_active' => true]);
        mfShift($this->rosinaEmployee, $elsewhere);

        expect(mfRing($this->rosina, $this->branch, 'cash')->shift_id)->toBeNull();
    });

    it('records nobody for a customer who paid online', function () {
        $payment = mfPaid(mfSession($this->branch, ['session_type' => 'online']));

        expect($payment->collected_by)->toBeNull()
            ->and($payment->shift_id)->toBeNull()
            ->and($payment->source)->toBe('online');
    });

    it('marks a recorded past order and finds the shift it was taken on', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch, [
            'login_at' => now()->subDay()->setTime(9, 0),
            'logout_at' => now()->subDay()->setTime(17, 0),
        ]);
        $session = mfSession($this->branch, [
            'staff_id' => $this->rosinaEmployee->id,
            'payment_method' => 'cash',
            'is_manual_entry' => true,
            'recorded_at' => now()->subDay()->setTime(12, 30),
            'manual_entry_reason' => 'Power cut',
        ]);

        $payment = mfPaid($session);

        expect($payment->source)->toBe('manual_entry')
            ->and($payment->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($payment->shift_id)->toBe($shift->id);
    });
});

describe('a shift\'s money', function () {
    it('adds up each method on its own, from the shift\'s payments only', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);
        $other = mfShift(mfStaff($this->branch, 'Kofi')['employee'], $this->branch);
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);
        $pay = fn (Shift $s, string $method, float $amount, string $status = 'completed') => Payment::create([
            'order_id' => $order->id,
            'payment_method' => $method,
            'payment_status' => $status,
            'amount' => $amount,
            'shift_id' => $s->id,
            'paid_at' => now(),
        ]);

        $pay($shift, 'cash', 40);
        $pay($shift, 'cash', 20);
        $pay($shift, 'mobile_money', 50);
        $pay($shift, 'manual_momo', 15);
        $pay($shift, 'card', 160);
        $pay($shift, 'mobile_money', 90, 'pending');
        $pay($shift, 'cash', 30, 'refunded');
        $pay($other, 'cash', 999);

        $money = app(ShiftMoney::class)->for($shift);

        expect($money['cash'])->toBe(['amount' => 60.0, 'count' => 2])
            ->and($money['momo'])->toBe(['amount' => 50.0, 'count' => 1])
            ->and($money['manual_momo'])->toBe(['amount' => 15.0, 'count' => 1])
            ->and($money['card'])->toBe(['amount' => 160.0, 'count' => 1])
            ->and($money['other'])->toBe(['amount' => 0.0, 'count' => 0]);
    });

    it('shows the cashier their own shift\'s money', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);
        mfRing($this->rosina, $this->branch, 'cash');

        $this->actingAs($this->rosina)
            ->getJson("/v1/shifts/{$shift->id}/money")
            ->assertOk()
            ->assertJsonPath('data.money.cash.count', 1);
    });

    it('does not show one cashier another\'s shift', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);
        ['user' => $kofi] = mfStaff($this->branch, 'Kofi');

        $this->actingAs($kofi)
            ->getJson("/v1/shifts/{$shift->id}/money")
            ->assertForbidden();
    });

    it('keeps the server\'s figures with the record of the shift ending', function () {
        $shift = mfShift($this->rosinaEmployee, $this->branch);
        mfRing($this->rosina, $this->branch, 'cash');

        $this->actingAs($this->rosina)
            ->patchJson("/v1/shifts/{$shift->id}/end")
            ->assertOk();

        $logged = \Spatie\Activitylog\Models\Activity::where('event', 'shift_ended')->latest('id')->first();
        expect($logged->properties['money']['cash']['count'])->toBe(1);
    });
});

describe('the backfill of past payments', function () {
    beforeEach(function () {
        $this->rung = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'assigned_employee_id' => $this->rosinaEmployee->id,
            'order_source' => 'pos',
        ]);
        $this->online = Order::factory()->create(['branch_id' => $this->branch->id, 'order_source' => 'online']);
        $this->shift = mfShift($this->rosinaEmployee, $this->branch);

        $old = fn (Order $order) => Payment::create([
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'amount' => 20,
            'paid_at' => now()->subHour(),
        ]);
        $this->tillPayment = $old($this->rung);
        $this->webPayment = $old($this->online);
    });

    it('writes nothing without --apply', function () {
        $this->artisan('payments:backfill-collectors')->assertSuccessful();

        expect($this->tillPayment->fresh()->source)->toBeNull()
            ->and($this->tillPayment->fresh()->collected_by)->toBeNull();
    });

    it('takes the collector from the order and marks it as a guess', function () {
        $this->artisan('payments:backfill-collectors', ['--apply' => true])->assertSuccessful();

        $till = $this->tillPayment->fresh();
        $web = $this->webPayment->fresh();

        expect($till->source)->toBe('till')
            ->and($till->collected_by)->toBe($this->rosinaEmployee->id)
            ->and($till->collector_inferred)->toBeTrue()
            ->and($till->shift_id)->toBeNull()
            ->and($web->source)->toBe('online')
            ->and($web->collected_by)->toBeNull()
            ->and($web->collector_inferred)->toBeFalse();
    });

    it('puts each payment on its person\'s shift only when asked', function () {
        $this->artisan('payments:backfill-collectors', ['--apply' => true, '--shifts' => true])->assertSuccessful();

        expect($this->tillPayment->fresh()->shift_id)->toBe($this->shift->id);
    });

    it('leaves a payment that already records its collector alone', function () {
        $this->tillPayment->forceFill(['source' => 'till', 'collected_by' => null])->saveQuietly();

        $this->artisan('payments:backfill-collectors', ['--apply' => true])->assertSuccessful();

        expect($this->tillPayment->fresh()->collected_by)->toBeNull();
    });
});
