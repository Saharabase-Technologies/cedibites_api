<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\BranchCodeRouter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Each branch code payment goes to the till of the branch it was for
|--------------------------------------------------------------------------
|
| Hubtel posts every branch's payments to one address and names no branch
| (D20). Our rules place each payment by what it carries: the payer's number,
| the amount and the time (D26, D35). What no rule can place waits on a list
| every till sees, and the first cashier to take it places it.
|
*/

const RT_PAYER = '233247879103';

function rtBranch(string $name): Branch
{
    return Branch::factory()->create(['name' => $name, 'is_active' => true]);
}

function rtOrder(Branch $branch, float $total, array $attrs = []): Order
{
    return Order::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'order_source' => 'pos',
        'order_type' => 'pickup',
        'status' => 'completed',
        'subtotal' => $total,
        'delivery_fee' => 0,
        'service_charge' => 0,
        'total_amount' => $total,
        'contact_phone' => '+233200000000',
        'created_at' => now(),
    ], $attrs));
}

function rtPayment(array $attrs = []): HubtelIncomingPayment
{
    static $n = 0;
    $n++;

    return HubtelIncomingPayment::create(array_merge([
        'branch_id' => null,
        'account_number' => '2040195',
        'client_reference' => "NHrt{$n}_".RT_PAYER.'_1',
        'network_transaction_id' => "8000000000{$n}",
        'payer_number' => RT_PAYER,
        'amount' => 80,
        'amount_charged' => 80.8,
        'paid_at' => now(),
        'verified_at' => now(),
    ], $attrs));
}

function rtRoute(HubtelIncomingPayment $payment): HubtelIncomingPayment
{
    app(BranchCodeRouter::class)->route($payment);

    return $payment->fresh();
}

beforeEach(function () {
    $this->lakeside = rtBranch('Lakeside');
    $this->ashaiman = rtBranch('Ashaiman');
    $this->eastLegon = rtBranch('East Legon');
});

describe('the rules', function () {
    it('sends a payment to the branch of the order with the payer\'s number and the amount', function () {
        $order = rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103', 'created_at' => now()->subMinutes(20)]);
        // Another branch also rang GHS 80, so the amount alone could not tell.
        rtOrder($this->ashaiman, 80, ['created_at' => now()->subMinutes(5)]);

        $payment = rtRoute(rtPayment());

        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->routed_by)->toBe(BranchCodeRouter::BY_NUMBER_AND_AMOUNT)
            ->and($payment->matched_order_id)->toBe($order->id)
            ->and($payment->routed_at)->not->toBeNull();
    });

    it('finds the number however the order kept it', function () {
        rtOrder($this->lakeside, 80, ['contact_phone' => '0247879103', 'created_at' => now()->subMinutes(50)]);

        expect(rtRoute(rtPayment())->routed_by)->toBe(BranchCodeRouter::BY_NUMBER_AND_AMOUNT);
    });

    it('matches the goods alone when the order carried a delivery fee', function () {
        rtOrder($this->lakeside, 95, ['delivery_fee' => 15, 'contact_phone' => '+233247879103']);

        expect(rtRoute(rtPayment())->branch_id)->toBe($this->lakeside->id);
    });

    it('sends a payment to the one branch with an order of that amount', function () {
        $order = rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(10)]);
        rtOrder($this->ashaiman, 75, ['created_at' => now()->subMinutes(10)]);

        $payment = rtRoute(rtPayment());

        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->routed_by)->toBe(BranchCodeRouter::BY_AMOUNT)
            ->and($payment->matched_order_id)->toBe($order->id);
    });

    it('takes the nearest order in time when one branch rang the amount twice', function () {
        rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(25)]);
        $near = rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(2)]);

        expect(rtRoute(rtPayment())->matched_order_id)->toBe($near->id);
    });

    it('leaves a payment shared when two branches rang the amount', function () {
        rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(10)]);
        rtOrder($this->ashaiman, 80, ['created_at' => now()->subMinutes(10)]);

        $payment = rtRoute(rtPayment());

        expect($payment->branch_id)->toBeNull()
            ->and($payment->routed_by)->toBeNull();
    });

    it('does not count an order of that amount rung too long before', function () {
        rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(45)]);

        expect(rtRoute(rtPayment())->branch_id)->toBeNull();
    });

    it('does not count a cancelled order', function () {
        rtOrder($this->lakeside, 80, ['status' => 'cancelled', 'contact_phone' => '+233247879103']);

        expect(rtRoute(rtPayment())->branch_id)->toBeNull();
    });

    it('sends a payment to the only branch the number has ordered at', function () {
        rtOrder($this->eastLegon, 45, ['contact_phone' => '+233247879103', 'created_at' => now()->subDays(10)]);

        $payment = rtRoute(rtPayment());

        expect($payment->branch_id)->toBe($this->eastLegon->id)
            ->and($payment->routed_by)->toBe(BranchCodeRouter::BY_HISTORY)
            ->and($payment->matched_order_id)->toBeNull();
    });

    it('leaves a payment shared when the number has ordered at two branches', function () {
        rtOrder($this->eastLegon, 45, ['contact_phone' => '+233247879103', 'created_at' => now()->subDays(10)]);
        rtOrder($this->ashaiman, 45, ['contact_phone' => '+233247879103', 'created_at' => now()->subDays(3)]);

        expect(rtRoute(rtPayment())->branch_id)->toBeNull();
    });

    it('leaves a payment with no number to the amount alone', function () {
        rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(10)]);

        expect(rtRoute(rtPayment(['payer_number' => null]))->routed_by)->toBe(BranchCodeRouter::BY_AMOUNT);
    });

    it('tries the rules in order', function () {
        // The number and amount say Lakeside. The amount alone, nearer in
        // time, would say Ashaiman. The number's history names two branches.
        rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103', 'created_at' => now()->subMinutes(60)]);
        rtOrder($this->ashaiman, 80, ['created_at' => now()->subMinutes(5)]);
        rtOrder($this->eastLegon, 30, ['contact_phone' => '+233247879103', 'created_at' => now()->subDays(4)]);

        $payment = rtRoute(rtPayment());

        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->routed_by)->toBe(BranchCodeRouter::BY_NUMBER_AND_AMOUNT);
    });

    it('never moves a payment a rule has already placed', function () {
        rtOrder($this->lakeside, 80, ['created_at' => now()->subMinutes(10)]);
        $payment = rtRoute(rtPayment());

        rtOrder($this->ashaiman, 80, ['contact_phone' => '+233247879103']);

        expect(rtRoute($payment)->branch_id)->toBe($this->lakeside->id);
    });

    it('never places a payment a cashier sent back', function () {
        $payment = rtPayment(['returned_at' => now()]);
        rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103']);

        expect(rtRoute($payment)->branch_id)->toBeNull();
    });
});

describe('an order rung after the payment', function () {
    it('places a shared payment once the order it was for is rung', function () {
        $payment = rtRoute(rtPayment(['paid_at' => now()->subMinutes(5)]));
        expect($payment->branch_id)->toBeNull();

        $order = rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103']);

        $payment->refresh();
        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->routed_by)->toBe(BranchCodeRouter::BY_NUMBER_AND_AMOUNT)
            ->and($payment->matched_order_id)->toBe($order->id);
    });

    it('leaves a payment from more than half an hour ago where it is', function () {
        $payment = rtPayment(['paid_at' => now()->subMinutes(40)]);

        rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103', 'created_at' => now()->subMinutes(15)]);

        expect($payment->fresh()->branch_id)->toBeNull();
    });

    it('does not retry for a recorded past order', function () {
        $payment = rtPayment(['paid_at' => now()->subMinutes(5)]);

        rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103', 'order_source' => 'manual_entry']);

        expect($payment->fresh()->branch_id)->toBeNull();
    });
});

describe('the till', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['name' => 'Rosina']);
        $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $this->employee->branches()->attach($this->lakeside);
        $user->syncRoles([RoleEnum::SalesStaff->value]);
        $this->cashier = $user->fresh();
    });

    it('lists this branch\'s payments and the shared ones, never another branch\'s', function () {
        $order = rtOrder($this->lakeside, 80, ['order_number' => 'AJ700', 'created_at' => now()->subMinutes(10)]);
        $ours = rtPayment(['branch_id' => $this->lakeside->id, 'routed_by' => 'amount', 'matched_order_id' => $order->id]);
        $shared = rtPayment();
        rtPayment(['branch_id' => $this->ashaiman->id, 'routed_by' => 'history']);

        $rows = collect($this->actingAs($this->cashier)
            ->getJson('/v1/pos/branch-code-payments?branch_id='.$this->lakeside->id)
            ->assertOk()
            ->json('data'))->keyBy('id');

        expect($rows)->toHaveCount(2)
            ->and($rows[$ours->id]['shared'])->toBeFalse()
            ->and($rows[$ours->id]['routed_by'])->toBe('amount')
            ->and($rows[$ours->id]['order_number'])->toBe('AJ700')
            ->and($rows[$shared->id]['shared'])->toBeTrue()
            ->and($rows[$shared->id]['routed_by'])->toBeNull();
    });

    it('lets a cashier take a shared payment for their branch', function () {
        $payment = rtPayment();

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/take", ['branch_id' => $this->lakeside->id])
            ->assertOk()
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.routed_by', 'cashier')
            ->assertJsonPath('data.taken_by', 'Rosina');

        $payment->refresh();
        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->taken_by)->toBe($this->employee->id);
    });

    it('says which branch got there first', function () {
        $payment = rtPayment(['branch_id' => $this->ashaiman->id, 'routed_by' => 'cashier']);

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/take", ['branch_id' => $this->lakeside->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ashaiman has already taken this payment.');

        expect($payment->fresh()->branch_id)->toBe($this->ashaiman->id);
    });

    it('sends a payment that is not this branch\'s back to the shared list, for good', function () {
        $payment = rtPayment(['branch_id' => $this->lakeside->id, 'routed_by' => 'amount']);

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/give-back", ['branch_id' => $this->lakeside->id])
            ->assertOk()
            ->assertJsonPath('data.shared', true);

        $payment->refresh();
        expect($payment->branch_id)->toBeNull()
            ->and($payment->routed_by)->toBeNull()
            ->and($payment->returned_by)->toBe($this->employee->id)
            ->and($payment->returned_at)->not->toBeNull();

        // A cashier has ruled on it, so a matching order no longer places it.
        rtOrder($this->lakeside, 80, ['contact_phone' => '+233247879103']);
        expect($payment->fresh()->branch_id)->toBeNull();
    });

    it('lets a payment sent back be taken by the branch it belongs to', function () {
        $payment = rtPayment(['returned_at' => now()]);

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/take", ['branch_id' => $this->lakeside->id])
            ->assertOk();

        expect($payment->fresh()->branch_id)->toBe($this->lakeside->id);
    });

    it('does not let a cashier send back another branch\'s payment', function () {
        $payment = rtPayment(['branch_id' => $this->ashaiman->id, 'routed_by' => 'history']);

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/give-back", ['branch_id' => $this->lakeside->id])
            ->assertStatus(409);

        expect($payment->fresh()->branch_id)->toBe($this->ashaiman->id);
    });

    it('does not let a cashier take a payment for another branch', function () {
        $payment = rtPayment();

        $this->actingAs($this->cashier)
            ->postJson("/v1/pos/branch-code-payments/{$payment->id}/take", ['branch_id' => $this->ashaiman->id])
            ->assertForbidden();

        expect($payment->fresh()->branch_id)->toBeNull();
    });
});
