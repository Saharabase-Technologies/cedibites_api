<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| The Finance ledger: who may read it, and what counts as a sale
|--------------------------------------------------------------------------
|
| GET /v1/admin/finance/ledger returns the rows every Finance screen and
| download is worked out from. Two promises are pinned here.
|
| 1. Nobody below Admin reads the figures of a branch they are not assigned
|    to, whatever `branch_id` or `branch_ids[]` they send.
|
| 2. A sale is a paid order that was not cancelled, worth its total less the
|    delivery fee. A cancelled order is never a sale, and a deleted order is
|    in nothing.
|
| Every amount in this file is made up.
|
*/

/**
 * A user and employee with a role, attached to the branches given.
 *
 * @param  list<Branch>  $branches
 */
function ledgerStaff(string $role, array $branches = []): User
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);

    foreach ($branches as $branch) {
        $employee->branches()->attach($branch);
    }

    $user->assignRole($role);

    return $user->fresh();
}

/**
 * An order at a branch with the payments given, one completed cash payment
 * unless told otherwise. A till pickup of 100 placed on 2026-02-10.
 *
 * @param  array<string, mixed>  $order
 * @param  list<array<string, mixed>>  $payments
 */
function ledgerOrder(Branch $branch, array $order = [], array $payments = [[]]): Order
{
    $order = Order::factory()->create([
        'branch_id' => $branch->id,
        'order_source' => 'pos',
        'order_type' => 'pickup',
        'status' => 'completed',
        'subtotal' => 100,
        'discount' => 0,
        'service_charge' => 0,
        'delivery_fee' => 0,
        'total_amount' => 100,
        'created_at' => '2026-02-10 12:00:00',
        ...$order,
    ]);

    foreach ($payments as $payment) {
        Payment::factory()->create([
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'payment_status' => 'completed',
            'amount' => $order->total_amount,
            ...$payment,
        ]);
    }

    return $order;
}

/**
 * An order cancelled after payment, with the payment left in the status given.
 *
 * @param  array<string, mixed>  $order
 */
function ledgerCancelledOrder(Branch $branch, string $paymentStatus, array $order = []): Order
{
    return ledgerOrder($branch, [
        'status' => 'cancelled',
        'cancelled_at' => '2026-02-10 12:20:00',
        'cancelled_reason' => 'Customer changed their mind',
        ...$order,
    ], [['payment_status' => $paymentStatus]]);
}

/**
 * A dish on a branch's menu with one option, named as given.
 */
function ledgerOption(Branch $branch, string $item, string $label, ?string $displayName, string $category): MenuItemOption
{
    $menuItem = MenuItem::factory()->create([
        'branch_id' => $branch->id,
        'category_id' => MenuCategory::factory()->create([
            'branch_id' => $branch->id,
            'name' => $category,
            'slug' => str($category)->slug()->value(),
        ])->id,
        'name' => $item,
    ]);

    // The factory gives every dish a Standard option. That one is renamed.
    $option = $menuItem->options()->firstOrFail();
    $option->update(['option_label' => $label, 'display_name' => $displayName]);

    return $option;
}

/**
 * A line on an order: so many of an option, for so much.
 */
function ledgerLine(Order $order, MenuItemOption $option, int $quantity, float $subtotal): OrderItem
{
    return OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'quantity' => $quantity,
        'unit_price' => $subtotal / $quantity,
        'subtotal' => $subtotal,
    ]);
}

/**
 * Read the ledger as the given user.
 *
 * @param  array<string, mixed>  $query
 */
function financeLedger(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->getJson('/v1/admin/finance/ledger?'.http_build_query($query));
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->ashaiman = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->lakeside = Branch::factory()->create(['name' => 'Lakeside']);
});

/*
|--------------------------------------------------------------------------
| Who may read it
|--------------------------------------------------------------------------
*/

describe('the ledger is held to the caller\'s branches', function () {
    it('gives a caller below admin only their own branches', function (string $role) {
        $caller = ledgerStaff($role, [$this->ashaiman]);
        ledgerOrder($this->ashaiman);
        ledgerOrder($this->lakeside);

        $data = financeLedger($caller)->assertSuccessful()->json('data');

        expect(array_column($data['branches'], 'id'))->toBe([$this->ashaiman->id])
            ->and(array_column($data['sales'], 1))->toBe([$this->ashaiman->id]);
    })->with([
        'a partner' => RoleEnum::BranchPartner->value,
        'a manager' => RoleEnum::Manager->value,
    ]);

    it('gives a partner nothing for a branch that is not theirs', function (string $parameter) {
        $partner = ledgerStaff(RoleEnum::BranchPartner->value, [$this->ashaiman]);
        ledgerOrder($this->ashaiman);

        $sale = ledgerOrder($this->lakeside);
        ledgerLine($sale, ledgerOption($this->lakeside, 'Jollof Rice', 'Large', null, 'Rice'), 2, 100);
        ledgerCancelledOrder($this->lakeside, 'refunded');

        $query = $parameter === 'branch_ids'
            ? ['branch_ids' => [$this->lakeside->id]]
            : ['branch_id' => $this->lakeside->id];

        $response = financeLedger($partner, $query)->assertSuccessful();

        expect($response->json('data.branches'))->toBe([])
            ->and($response->json('data.sales'))->toBe([])
            ->and($response->json('data.cancelled'))->toBe([])
            ->and($response->json('data.items'))->toBe([])
            ->and($response->getContent())->toContain('"options":{}');
    })->with(['branch_ids', 'branch_id']);

    it('drops the branch that is not theirs when a partner asks for both', function () {
        $partner = ledgerStaff(RoleEnum::BranchPartner->value, [$this->ashaiman]);
        ledgerOrder($this->ashaiman);
        ledgerOrder($this->lakeside);
        ledgerCancelledOrder($this->lakeside, 'completed');

        $data = financeLedger($partner, ['branch_ids' => [$this->ashaiman->id, $this->lakeside->id]])
            ->assertSuccessful()
            ->json('data');

        expect(array_column($data['branches'], 'id'))->toBe([$this->ashaiman->id])
            ->and(array_column($data['sales'], 1))->toBe([$this->ashaiman->id])
            ->and($data['cancelled'])->toBe([]);
    });

    it('gives a partner with no branches nothing at all', function () {
        $partner = ledgerStaff(RoleEnum::BranchPartner->value);

        $sale = ledgerOrder($this->ashaiman);
        ledgerLine($sale, ledgerOption($this->ashaiman, 'Jollof Rice', 'Large', null, 'Rice'), 2, 100);
        ledgerCancelledOrder($this->ashaiman, 'refunded');

        $response = financeLedger($partner)->assertSuccessful();

        expect($response->json('data.branches'))->toBe([])
            ->and($response->json('data.sales'))->toBe([])
            ->and($response->json('data.cancelled'))->toBe([])
            ->and($response->json('data.items'))->toBe([])
            ->and($response->getContent())->toContain('"options":{}');
    });

    it('refuses the cashier', function () {
        $cashier = ledgerStaff(RoleEnum::SalesStaff->value, [$this->ashaiman]);
        ledgerOrder($this->ashaiman);

        financeLedger($cashier)->assertForbidden();
    });

    it('shows the admin every branch, and one branch when asked', function () {
        $admin = ledgerStaff(RoleEnum::Admin->value, [$this->ashaiman]);
        ledgerOrder($this->ashaiman);
        ledgerOrder($this->lakeside);

        $all = financeLedger($admin)->assertSuccessful()->json('data');

        expect(array_column($all['branches'], 'id'))->toBe([$this->ashaiman->id, $this->lakeside->id])
            ->and(array_column($all['sales'], 1))->toBe([$this->ashaiman->id, $this->lakeside->id]);

        $one = financeLedger($admin, ['branch_ids' => [$this->lakeside->id]])->assertSuccessful()->json('data');

        expect(array_column($one['branches'], 'id'))->toBe([$this->lakeside->id])
            ->and(array_column($one['sales'], 1))->toBe([$this->lakeside->id]);
    });
});

/*
|--------------------------------------------------------------------------
| What counts as a sale
|--------------------------------------------------------------------------
*/

describe('a sale is a paid order that was not cancelled', function () {
    beforeEach(function () {
        $this->partner = ledgerStaff(RoleEnum::BranchPartner->value, [$this->ashaiman]);
    });

    it('leaves the delivery fee out of net sales', function () {
        // 200 of food, 10 off, 5 service charge and 15 for the rider: the
        // customer paid 210 and the restaurant earned 195.
        ledgerOrder($this->ashaiman, [
            'order_source' => 'online',
            'order_type' => 'delivery',
            'subtotal' => 200,
            'discount' => 10,
            'service_charge' => 5,
            'delivery_fee' => 15,
            'total_amount' => 210,
        ], [['payment_method' => 'mobile_money']]);

        // date, branch, source, type, method, paid orders, gross, discount,
        // service charge, net, delivery fee.
        expect(financeLedger($this->partner)->assertSuccessful()->json('data.sales'))->toEqual([
            ['2026-02-10', $this->ashaiman->id, 'online', 'delivery', 'mobile_money', 1, 200, 10, 5, 195, 15],
        ]);
    });

    it('adds up orders of the same day, branch, source, type and method', function () {
        ledgerOrder($this->ashaiman);
        ledgerOrder($this->ashaiman, ['subtotal' => 50, 'total_amount' => 50]);
        ledgerOrder($this->ashaiman, ['subtotal' => 30, 'total_amount' => 30], [['payment_method' => 'mobile_money']]);
        ledgerOrder($this->ashaiman, ['created_at' => '2026-02-11 09:00:00']);

        expect(financeLedger($this->partner)->assertSuccessful()->json('data.sales'))->toEqual([
            ['2026-02-10', $this->ashaiman->id, 'pos', 'pickup', 'cash', 2, 150, 0, 0, 150, 0],
            ['2026-02-10', $this->ashaiman->id, 'pos', 'pickup', 'mobile_money', 1, 30, 0, 0, 30, 0],
            ['2026-02-11', $this->ashaiman->id, 'pos', 'pickup', 'cash', 1, 100, 0, 0, 100, 0],
        ]);
    });

    it('counts an order paid in two parts once, under its first payment', function () {
        $order = ledgerOrder($this->ashaiman, [], [
            ['payment_method' => 'cash', 'amount' => 60],
            ['payment_method' => 'mobile_money', 'amount' => 40],
        ]);
        $option = ledgerOption($this->ashaiman, 'Jollof Rice', 'Large', null, 'Rice');
        ledgerLine($order, $option, 2, 100);

        $data = financeLedger($this->partner)->assertSuccessful()->json('data');

        expect($data['sales'])->toEqual([
            ['2026-02-10', $this->ashaiman->id, 'pos', 'pickup', 'cash', 1, 100, 0, 0, 100, 0],
        ])->and($data['items'])->toEqual([
            ['2026-02', $this->ashaiman->id, 'pos', 'pickup', 'cash', $option->id, 2, 100],
        ]);
    });

    it('does not count an order that was never paid, or whose payment was deleted', function () {
        ledgerOrder($this->ashaiman, [], [['payment_status' => 'pending']]);
        ledgerOrder($this->ashaiman, [], [['payment_status' => 'failed']]);

        $deleted = ledgerOrder($this->ashaiman);
        $deleted->payments()->delete();

        $data = financeLedger($this->partner)->assertSuccessful()->json('data');

        expect($data['sales'])->toBe([])
            ->and($data['branches'][0]['first_sale'])->toBeNull();
    });

    it('files an order cancelled after payment under what became of the money', function () {
        // 75 of food, 5 service charge and 15 for the rider. The amount is the
        // 95 paid less the rider's 15, which no other column adds up to.
        ledgerCancelledOrder($this->ashaiman, 'refunded', [
            'order_number' => 'TEST01',
            'order_source' => 'online',
            'order_type' => 'delivery',
            'subtotal' => 75,
            'service_charge' => 5,
            'delivery_fee' => 15,
            'total_amount' => 95,
            'created_at' => '2026-02-10 13:22:10',
            'cancelled_at' => '2026-02-10 13:40:55',
            'cancelled_reason' => ' Duplicate order ',
        ]);
        ledgerCancelledOrder($this->ashaiman, 'completed', ['order_number' => 'TEST02', 'created_at' => '2026-02-10 14:00:00']);
        ledgerCancelledOrder($this->ashaiman, 'no_charge', ['order_number' => 'TEST03', 'created_at' => '2026-02-10 15:00:00']);

        // Cancelled before any money moved: it is in neither list.
        ledgerCancelledOrder($this->ashaiman, 'failed', ['order_number' => 'TEST04']);

        $data = financeLedger($this->partner)->assertSuccessful()->json('data');

        expect(array_column($data['cancelled'], 'outcome', 'order_number'))->toBe([
            'TEST01' => 'refunded',
            'TEST02' => 'kept',
            'TEST03' => 'no_charge',
        ])->and($data['cancelled'][0])->toEqual([
            'order_number' => 'TEST01',
            'placed_at' => '2026-02-10 13:22',
            'cancelled_at' => '2026-02-10 13:40',
            'branch_id' => $this->ashaiman->id,
            'source' => 'online',
            'type' => 'delivery',
            'method' => 'cash',
            'outcome' => 'refunded',
            'amount' => 80,
            'reason' => 'Duplicate order',
        ])->and($data['sales'])->toBe([])
            ->and($data['branches'][0]['first_sale'])->toBeNull();
    });

    it('leaves a deleted order out of everything', function () {
        $sale = ledgerOrder($this->ashaiman);
        ledgerLine($sale, ledgerOption($this->ashaiman, 'Jollof Rice', 'Large', null, 'Rice'), 2, 100);
        $cancelled = ledgerCancelledOrder($this->ashaiman, 'refunded');

        $sale->delete();
        $cancelled->delete();

        $response = financeLedger($this->partner)->assertSuccessful();

        expect($response->json('data.branches'))->toBe([
            ['id' => $this->ashaiman->id, 'name' => 'Ashaiman', 'first_sale' => null],
        ])->and($response->json('data.sales'))->toBe([])
            ->and($response->json('data.cancelled'))->toBe([])
            ->and($response->json('data.items'))->toBe([])
            ->and($response->getContent())->toContain('"options":{}');
    });
});

/*
|--------------------------------------------------------------------------
| The shape the frontend reads
|--------------------------------------------------------------------------
*/

describe('the ledger comes back in the shape the frontend reads', function () {
    beforeEach(function () {
        $this->partner = ledgerStaff(RoleEnum::BranchPartner->value, [$this->ashaiman]);
    });

    it('keeps the first sale whatever dates are asked for', function () {
        ledgerOrder($this->ashaiman);
        ledgerOrder($this->ashaiman, ['created_at' => '2026-05-10 12:00:00']);
        ledgerCancelledOrder($this->ashaiman, 'refunded');

        $data = financeLedger($this->partner, ['from' => '2026-05-01', 'to' => '2026-05-31'])
            ->assertSuccessful()
            ->json('data');

        expect($data['branches'])->toBe([
            ['id' => $this->ashaiman->id, 'name' => 'Ashaiman', 'first_sale' => '2026-02-10'],
        ])->and(array_column($data['sales'], 0))->toBe(['2026-05-10'])
            ->and($data['cancelled'])->toBe([]);
    });

    it('returns items by month, and each option\'s names apart for the frontend to join', function () {
        $option = ledgerOption($this->ashaiman, 'Jollof Rice / Fried Rice', 'Large Jollof', 'Large Jollof Rice', 'Rice');
        $order = ledgerOrder($this->ashaiman);
        ledgerLine($order, $option, 3, 90);

        // A line sold with no option comes back under option 0, unnamed.
        ledgerLine($order, $option, 1, 10)->update(['menu_item_option_id' => null]);

        $data = financeLedger($this->partner)->assertSuccessful()->json('data');

        // month, branch, source, type, method, option id, quantity, sales.
        expect($data['items'])->toEqual([
            ['2026-02', $this->ashaiman->id, 'pos', 'pickup', 'cash', 0, 1, 10],
            ['2026-02', $this->ashaiman->id, 'pos', 'pickup', 'cash', $option->id, 3, 90],
        ])->and($data['options'])->toBe([
            $option->id => [
                'item' => 'Jollof Rice / Fried Rice',
                'display_name' => 'Large Jollof Rice',
                'label' => 'Large Jollof',
                'category' => 'Rice',
            ],
        ]);
    });

    it('stamps the read with the server\'s clock', function () {
        $this->travelTo(Carbon::parse('2026-10-14 00:14:03'));

        $data = financeLedger($this->partner)->assertSuccessful()->json('data');

        expect(array_keys($data))->toBe(['read_at', 'today', 'branches', 'sales', 'cancelled', 'items', 'options'])
            ->and($data['read_at'])->toBe('2026-10-14T00:14:03Z')
            ->and($data['today'])->toBe('2026-10-14');
    });
});
