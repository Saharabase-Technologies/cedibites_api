<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| A shift that really ends, Clock out, and the manager's confirm
|--------------------------------------------------------------------------
|
| Staff portal plan, Section 7, Shifts. Sign out ends the shift on the
| server. Clock out keeps what the person confirmed, as it was at the
| button. The branch manager confirms the shift afterwards.
|
*/

function coStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

function coShift(Employee $employee, Branch $branch, array $attrs = []): Shift
{
    return Shift::create(array_merge([
        'employee_id' => $employee->id,
        'branch_id' => $branch->id,
        'login_at' => now()->subHours(3),
        'total_sales' => 0,
        'order_count' => 0,
    ], $attrs));
}

function coPay(Shift $shift, float $amount, string $method = 'cash', ?Order $order = null): Payment
{
    $order ??= Order::factory()->create([
        'branch_id' => $shift->branch_id,
        'assigned_employee_id' => $shift->employee_id,
        'subtotal' => $amount, 'delivery_fee' => 0, 'service_charge' => 0, 'total_amount' => $amount,
        'status' => 'completed',
    ]);

    return Payment::create([
        'order_id' => $order->id,
        'payment_method' => $method,
        'payment_status' => 'completed',
        'amount' => $amount,
        'collected_by' => $shift->employee_id,
        'shift_id' => $shift->id,
        'paid_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->lakeside = Branch::factory()->create(['name' => 'Lakeside', 'is_active' => true]);
    $this->eastLegon = Branch::factory()->create(['name' => 'East Legon', 'is_active' => true]);

    ['user' => $this->kofi, 'employee' => $this->kofiEmployee] = coStaff($this->lakeside, 'Kofi Asante');
    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = coStaff($this->lakeside, 'Rosina Mensah');
    ['user' => $this->kwabena, 'employee' => $this->kwabenaEmployee] = coStaff($this->lakeside, 'Kwabena Ofori', RoleEnum::Manager->value);
    ['user' => $this->admin, 'employee' => $this->adminEmployee] = coStaff($this->lakeside, 'Platform Admin', RoleEnum::Admin->value);
});

describe('a shift that really ends', function () {
    it('ends the open shift when the person signs out', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        $this->kofi->createToken('employee-auth-token', ['staff']);

        $this->actingAs($this->kofi)->postJson('/v1/employee/logout')->assertSuccessful();

        expect($shift->fresh()->logout_at)->not->toBeNull()
            ->and($shift->fresh()->clocked_out_at)->toBeNull()
            ->and($this->kofi->tokens()->count())->toBe(0);
    });
});

describe('Clock out', function () {
    it('shows the money, the money from other people\'s orders and what is still owed', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        coPay($shift, 80);
        $rosinas = Order::factory()->create(['branch_id' => $this->lakeside->id, 'assigned_employee_id' => $this->rosinaEmployee->id, 'status' => 'completed']);
        coPay($shift, 50, 'cash', $rosinas);
        Order::factory()->create([
            'branch_id' => $this->lakeside->id, 'assigned_employee_id' => $this->kofiEmployee->id,
            'subtotal' => 115, 'delivery_fee' => 0, 'service_charge' => 0, 'total_amount' => 115,
            'status' => 'preparing', 'contact_name' => 'Abena Darko',
        ]);

        $this->actingAs($this->kofi)->getJson("/v1/shifts/{$shift->id}/clock-out")
            ->assertOk()
            ->assertJsonPath('data.total', 130)
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.money.cash.amount', 130)
            ->assertJsonPath('data.on_others_orders.0.assigned_to', 'Rosina')
            ->assertJsonPath('data.on_others_orders.0.amount', 50)
            ->assertJsonPath('data.owing.0.customer', 'Abena Darko')
            ->assertJsonPath('data.owing.0.amount', 115);
    });

    it('keeps what the person saw, ends the shift and signs them out', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        coPay($shift, 80);
        coPay($shift, 40, 'mobile_money');
        $this->kofi->createToken('employee-auth-token', ['staff']);

        $this->actingAs($this->kofi)
            ->postJson("/v1/shifts/{$shift->id}/clock-out", ['note' => 'All good', 'seen_total' => 120])
            ->assertOk()
            ->assertJsonPath('data.clockOut.note', 'All good');

        $shift->refresh();
        expect($shift->logout_at)->not->toBeNull()
            ->and($shift->clocked_out_at)->not->toBeNull()
            ->and($shift->clock_out_money['total'])->toEqual(120)
            ->and($shift->clock_out_money['money']['momo']['amount'])->toEqual(40)
            ->and($shift->clock_out_note)->toBe('All good')
            ->and($this->kofi->tokens()->count())->toBe(0);
    });

    it('refuses a second Clock out', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        $this->actingAs($this->kofi)->postJson("/v1/shifts/{$shift->id}/clock-out")->assertOk();

        $this->actingAs($this->kofi)->postJson("/v1/shifts/{$shift->id}/clock-out")->assertStatus(409);
    });

    it('refuses somebody else\'s shift', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);

        $this->actingAs($this->rosina)->postJson("/v1/shifts/{$shift->id}/clock-out")->assertForbidden();
        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$shift->id}/clock-out")->assertForbidden();

        expect($shift->fresh()->logout_at)->toBeNull();
    });

    it('stores nothing when the money moved since the sheet was opened', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        coPay($shift, 80);

        $this->actingAs($this->kofi)
            ->postJson("/v1/shifts/{$shift->id}/clock-out", ['seen_total' => 50])
            ->assertStatus(409)
            ->assertJsonPath('data.total', 80);

        expect($shift->fresh()->logout_at)->toBeNull()
            ->and($shift->fresh()->clocked_out_at)->toBeNull();
    });

    it('leaves what was confirmed alone when a payment lands on the shift afterwards', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        coPay($shift, 80);
        $this->actingAs($this->kofi)->postJson("/v1/shifts/{$shift->id}/clock-out")->assertOk();

        coPay($shift->fresh(), 25);

        expect($shift->fresh()->clock_out_money['total'])->toEqual(80);

        $this->actingAs($this->kwabena)->getJson("/v1/manager/branches/{$this->lakeside->id}/shift-sales")
            ->assertOk()
            ->assertJsonPath('data.0.total', 105)
            ->assertJsonPath('data.0.clock_out.money.total', 80);
    });
});

describe('the manager\'s confirm', function () {
    it('lets the branch manager confirm an ended shift, once, with a note', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside, ['logout_at' => now()]);

        $this->actingAs($this->kwabena)
            ->postJson("/v1/shifts/{$shift->id}/confirm", ['note' => 'Counted with him'])
            ->assertOk()
            ->assertJsonPath('data.managerConfirmedBy', 'Kwabena Ofori')
            ->assertJsonPath('data.managerNote', 'Counted with him');

        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$shift->id}/confirm")->assertStatus(409);
    });

    it('refuses a shift still open', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);

        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$shift->id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kofi is still on shift.');
    });

    it('never lets a manager confirm their own shift or another branch\'s', function () {
        $own = coShift($this->kwabenaEmployee, $this->lakeside, ['logout_at' => now()]);
        ['employee' => $esi] = coStaff($this->eastLegon, 'Esi Quaye');
        $elsewhere = coShift($esi, $this->eastLegon, ['logout_at' => now()]);

        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$own->id}/confirm")->assertForbidden();
        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$elsewhere->id}/confirm")->assertForbidden();
    });

    it('never lets a cashier confirm', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside, ['logout_at' => now()]);

        $this->actingAs($this->rosina)->postJson("/v1/shifts/{$shift->id}/confirm")->assertForbidden();

        expect($shift->fresh()->manager_confirmed_at)->toBeNull();
    });

    it('lets the admin confirm at a branch with no manager, and not where there is one', function () {
        ['employee' => $esi] = coStaff($this->eastLegon, 'Esi Quaye');
        $noManager = coShift($esi, $this->eastLegon, ['logout_at' => now()]);
        $managed = coShift($this->kofiEmployee, $this->lakeside, ['logout_at' => now()]);

        $this->actingAs($this->admin)->postJson("/v1/shifts/{$noManager->id}/confirm")->assertOk();
        $this->actingAs($this->admin)->postJson("/v1/shifts/{$managed->id}/confirm")->assertForbidden();
    });

    it('asks nobody to confirm a manager\'s own shift', function () {
        ['user' => $ama] = coStaff($this->lakeside, 'Ama Boateng', RoleEnum::Manager->value);
        $kwabenas = coShift($this->kwabenaEmployee, $this->lakeside, ['logout_at' => now()]);

        $this->actingAs($ama)->postJson("/v1/shifts/{$kwabenas->id}/confirm")->assertStatus(422);
    });
});

describe('Staff Sales by shift', function () {
    it('gives the manager their branch only, and the admin every branch', function () {
        coShift($this->kofiEmployee, $this->lakeside);
        ['employee' => $esi] = coStaff($this->eastLegon, 'Esi Quaye');
        coShift($esi, $this->eastLegon);

        $this->actingAs($this->kwabena)->getJson("/v1/manager/branches/{$this->lakeside->id}/shift-sales")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.branch_name', 'Lakeside');

        $this->actingAs($this->kwabena)->getJson("/v1/manager/branches/{$this->eastLegon->id}/shift-sales")
            ->assertForbidden();

        $this->actingAs($this->admin)->getJson('/v1/admin/staff/shift-sales')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->kwabena)->getJson('/v1/admin/staff/shift-sales')->assertForbidden();
    });

    it('carries both sign-offs, the orders and whether the branch has a manager', function () {
        $shift = coShift($this->kofiEmployee, $this->lakeside);
        coPay($shift, 90);
        $this->actingAs($this->kofi)->postJson("/v1/shifts/{$shift->id}/clock-out", ['note' => 'Short ₵5'])->assertOk();
        $this->actingAs($this->kwabena)->postJson("/v1/shifts/{$shift->id}/confirm", ['note' => 'Found it'])->assertOk();

        $this->actingAs($this->admin)->getJson('/v1/admin/staff/shift-sales')
            ->assertOk()
            ->assertJsonPath('data.0.staff_name', 'Kofi Asante')
            ->assertJsonPath('data.0.on_shift', false)
            ->assertJsonPath('data.0.branch_has_manager', true)
            ->assertJsonPath('data.0.clock_out.note', 'Short ₵5')
            ->assertJsonPath('data.0.manager_confirm.by_name', 'Kwabena Ofori')
            ->assertJsonPath('data.0.manager_confirm.note', 'Found it')
            ->assertJsonPath('data.0.orders_attended', 1)
            ->assertJsonPath('data.0.order_value', 90);
    });
});
