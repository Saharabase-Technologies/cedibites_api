<?php

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Split payment: a new sale paid in parts at the counter (Phase 5, D7)
|--------------------------------------------------------------------------
|
| The order is placed with nothing paid and reaches the kitchen at once
| (D41). The cashier takes its parts straight away, by any method. It is not
| Pay later, so it is never marked paid_later (D42). A customer who walks off
| with one part paid leaves it Part paid, on the list of what is owed (D43).
|
*/

function spStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/** Rosina taps Split for Ama's order. */
function spPlace(User $cashier, Branch $branch, array $overrides = [])
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Jollof']);
    $dish->branches()->attach($branch->id, ['is_available' => true]);
    $dish = $dish->fresh(['options']);

    return test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', array_merge([
        'branch_id' => $branch->id,
        'items' => [[
            'menu_item_id' => $dish->id,
            'menu_item_option_id' => $dish->options->first()->id,
            'quantity' => 1,
            'unit_price' => 90,
        ]],
        'payment_method' => 'split',
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Ama',
        'contact_phone' => '+233247879103',
    ], $overrides));
}

function spTake(User $cashier, Order $order, array $body)
{
    return test()->actingAs($cashier)->postJson("/v1/pos/orders/{$order->id}/payments", $body);
}

function spHubtelPrompt(): void
{
    config()->set('services.hubtel.rmp_client_id', 'company-id');
    config()->set('services.hubtel.rmp_client_secret', 'company-secret');
    config()->set('services.hubtel.payment_client_id', 'company-id');
    config()->set('services.hubtel.payment_client_secret', 'company-secret');
    config()->set('services.hubtel.merchant_account_number', '2038092');

    Http::fake([
        'rmp.hubtel.com/*' => fn () => Http::response([
            'ResponseCode' => '0001',
            'Message' => 'Transaction pending. Expect callback request for final state',
            'Data' => ['TransactionId' => 'hub-tx-'.uniqid('', true), 'ClientReference' => 'x'],
        ]),
    ]);
}

function spCallback(string $reference, string $code = '0000')
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

function spOwingIds(User $cashier, Branch $branch)
{
    return collect(test()->actingAs($cashier)
        ->getJson('/v1/employee/orders?owing=1&branch_id='.$branch->id)
        ->assertOk()
        ->json('data.data'))->pluck('id');
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

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = spStaff($this->branch, 'Rosina');
});

describe('placing a split sale', function () {
    it('makes the order at once with nothing paid, marked split and not Pay later', function () {
        $response = spPlace($this->rosina, $this->branch)
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('order.split_payment', true)
            ->assertJsonPath('order.paid_later', false)
            ->assertJsonPath('order.payment_state', 'not_paid');

        $order = Order::findOrFail($response->json('order.id'));

        expect($order->split_payment)->toBeTrue()
            ->and($order->paid_later)->toBeFalse()
            ->and($order->placed_unpaid_at)->not->toBeNull()
            ->and($order->owing_since)->not->toBeNull()
            ->and($order->payments()->count())->toBe(0)
            ->and($order->assigned_employee_id)->toBe($this->rosinaEmployee->id);
    });

    it('is not offered for a recorded past order', function () {
        spPlace($this->rosina, $this->branch, [
            'is_manual_entry' => true,
            'recorded_at' => now()->subHour()->toIso8601String(),
            'manual_entry_reason' => 'Power cut',
        ])->assertStatus(422);

        expect(Order::count())->toBe(0);
    });

    it('is not offered for items added to an order', function () {
        $order = Order::findOrFail(spPlace($this->rosina, $this->branch, ['fulfillment_type' => 'dine_in'])->json('order.id'));

        spPlace($this->rosina, $this->branch, ['add_to_order_id' => $order->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_add_to_order');
    });

    it('takes the stock when it is placed, and gives it back if it is cancelled unpaid', function () {
        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldReceive('deductForOrder')->once();
        $stock->shouldReceive('reverseForOrder')->once();

        $order = Order::findOrFail(spPlace($this->rosina, $this->branch)->json('order.id'));
        $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    });
});

describe('the kitchen and the Order Manager', function () {
    it('shows a split sale on both boards as soon as it is placed, before any part is in (D41)', function () {
        $order = Order::findOrFail(spPlace($this->rosina, $this->branch)->json('order.id'));
        ['user' => $cook] = spStaff($this->branch, 'Kojo', RoleEnum::Kitchen->value);

        $board = collect($this->actingAs($this->rosina)
            ->getJson('/v1/order-manager/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        $kitchen = collect($this->actingAs($cook)
            ->getJson('/v1/kitchen/orders?branch_id='.$this->branch->id)
            ->assertOk()
            ->json('data'))->pluck('id');

        expect($board)->toContain($order->id)
            ->and($kitchen)->toContain($order->id);
    });
});

describe('taking the parts', function () {
    beforeEach(function () {
        $this->order = Order::findOrFail(spPlace($this->rosina, $this->branch)->json('order.id'));
        $this->owed = $this->order->goods_amount;
    });

    it('takes a MoMo part and the rest in cash with change, each marked split', function () {
        spHubtelPrompt();

        spTake($this->rosina, $this->order, ['method' => 'mobile_money', 'amount' => 1, 'momo_number' => '0241234567'])
            ->assertCreated();
        spCallback('PL-'.$this->order->order_number.'-1')->assertOk();

        expect($this->order->fresh()->payment_state)->toBe('part_paid');

        $rest = round($this->owed - 1, 2);
        $response = spTake($this->rosina, $this->order, ['method' => 'cash', 'handed_over' => $rest + 5])
            ->assertCreated()
            ->assertJsonPath('data.order.payment_state', 'paid');

        $order = $this->order->fresh();
        expect((float) $response->json('data.change'))->toBe(5.0)
            ->and($order->payments()->where('payment_status', 'completed')->pluck('source')->unique()->all())->toBe(['split'])
            ->and($order->fully_paid_at)->not->toBeNull()
            ->and($order->owing_since)->toBeNull()
            ->and($order->paid_later)->toBeFalse();
    });

    it('leaves the order not paid and still owed when a MoMo part is declined', function () {
        spHubtelPrompt();

        spTake($this->rosina, $this->order, ['method' => 'mobile_money', 'amount' => 1, 'momo_number' => '0241234567'])
            ->assertCreated();
        spCallback('PL-'.$this->order->order_number.'-1', '2001')->assertOk();

        $order = $this->order->fresh();
        expect($order->payment_state)->toBe('not_paid')
            ->and($order->owing_since)->not->toBeNull()
            ->and(spOwingIds($this->rosina, $this->branch))->toContain($order->id);
    });

    it('is Part paid and on the list of what is owed when the customer walks off after one part (D43)', function () {
        spTake($this->rosina, $this->order, ['method' => 'cash', 'handed_over' => 10])->assertCreated();

        $order = $this->order->fresh();
        expect($order->payment_state)->toBe('part_paid')
            ->and($order->owing_since)->not->toBeNull()
            ->and(spOwingIds($this->rosina, $this->branch)->all())->toBe([$order->id]);
    });

    it('drops off the list of what is owed once every part is in', function () {
        spTake($this->rosina, $this->order, ['method' => 'cash', 'handed_over' => 10])->assertCreated();
        spTake($this->rosina, $this->order, ['method' => 'card', 'amount' => round($this->owed - 10, 2)])->assertCreated();

        expect(spOwingIds($this->rosina, $this->branch)->all())->toBe([]);
    });

    it('keeps the stock taken when one part is refunded', function () {
        spTake($this->rosina, $this->order, ['method' => 'cash', 'handed_over' => 10])->assertCreated();
        spTake($this->rosina, $this->order, ['method' => 'card', 'amount' => round($this->owed - 10, 2)])->assertCreated();

        $stock = $this->mock(RecipeDeductionService::class);
        $stock->shouldNotReceive('reverseForOrder');

        Payment::where('order_id', $this->order->id)->where('payment_method', 'cash')->first()
            ->update(['payment_status' => 'refunded', 'refunded_at' => now()]);
    });
});

describe('the kitchen slip', function () {
    it('is not printed for a split sale (D42)', function () {
        $order = Order::findOrFail(spPlace($this->rosina, $this->branch)->json('order.id'));

        $this->actingAs($this->rosina)
            ->postJson("/v1/employee/orders/{$order->id}/receipt-printed", ['slip' => 'kitchen'])
            ->assertStatus(422);
    });
});
