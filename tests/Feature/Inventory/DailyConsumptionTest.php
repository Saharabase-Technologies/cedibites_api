<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the kitchen used today
|--------------------------------------------------------------------------
|
| Reads the `sale` movements the recipe deduction writes, so it is the ledger's
| own account of consumption rather than a projection from orders. A dish that
| sold without deducting is honestly absent rather than silently assumed.
|
*/

function consumptionStaff(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create([
        'user_id' => $user->id,
        'status' => EmployeeStatus::Active,
    ]);
    if ($branch) {
        $employee->branches()->attach($branch);
    }
    $user->assignRole($role);

    return $user->fresh();
}

function saleMovement(Item $item, Location $location, float $qty, ?Order $order, string $at): void
{
    DB::table('inventory_stock_movements')->insert([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => -abs($qty),
        'movement_type' => 'sale',
        'reference_type' => $order ? 'order' : null,
        'reference_id' => $order?->id,
        'idempotency_key' => 'test-'.uniqid('', true),
        'occurred_at' => $at,
        'created_at' => $at,
    ]);
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->location = Location::factory()->create([
        'branch_id' => $this->branch->id, 'type' => 'satellite', 'is_active' => true,
    ]);
    $this->chicken = Item::factory()->create(['name' => 'Chicken']);
    $this->rice = Item::factory()->create(['name' => 'Rice']);
});

describe('the daily consumption report', function () {
    it('totals each item used today', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id, 'order_number' => 'AF900']);
        saleMovement($this->chicken, $this->location, 3, $order, now()->toDateTimeString());
        saleMovement($this->chicken, $this->location, 2, $order, now()->toDateTimeString());
        saleMovement($this->rice, $this->location, 1, $order, now()->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        $chicken = collect($data['items'])->firstWhere('name', 'Chicken');

        // Sale movements are stored negative; consumption reads as positive.
        expect((float) $chicken['quantity'])->toBe(5.0)
            ->and($chicken['movements'])->toBe(2)
            ->and($data['totals']['items'])->toBe(2);
    });

    it('names the orders that consumed each item', function () {
        $a = Order::factory()->create(['branch_id' => $this->branch->id, 'order_number' => 'AF901']);
        $b = Order::factory()->create(['branch_id' => $this->branch->id, 'order_number' => 'AF902']);
        saleMovement($this->chicken, $this->location, 2, $a, now()->toDateTimeString());
        saleMovement($this->chicken, $this->location, 4, $b, now()->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        $orders = collect($data['items'])->firstWhere('name', 'Chicken')['orders'];

        expect(collect($orders)->pluck('order_number')->sort()->values()->all())->toBe(['AF901', 'AF902'])
            ->and((float) collect($orders)->firstWhere('order_number', 'AF902')['quantity'])->toBe(4.0);
    });

    it('sorts the heaviest consumption first', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);
        saleMovement($this->chicken, $this->location, 1, $order, now()->toDateTimeString());
        saleMovement($this->rice, $this->location, 9, $order, now()->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        expect($data['items'][0]['name'])->toBe('Rice');
    });

    it('leaves out yesterday', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);
        saleMovement($this->chicken, $this->location, 5, $order, now()->subDay()->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        expect($data['items'])->toBe([]);
    });

    it('reports a past day when asked', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);
        $yesterday = now()->subDay();
        saleMovement($this->chicken, $this->location, 5, $order, $yesterday->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption?date='.$yesterday->toDateString())
            ->assertSuccessful()
            ->json('data');

        expect($data['date'])->toBe($yesterday->toDateString())
            ->and((float) collect($data['items'])->firstWhere('name', 'Chicken')['quantity'])->toBe(5.0);
    });

    it('ignores movements that are not sales', function () {
        $order = Order::factory()->create(['branch_id' => $this->branch->id]);
        saleMovement($this->chicken, $this->location, 2, $order, now()->toDateTimeString());
        DB::table('inventory_stock_movements')->insert([
            'item_id' => $this->chicken->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'movement_type' => 'purchase',
            'idempotency_key' => 'test-purchase-'.uniqid('', true),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $data = $this->actingAs(consumptionStaff(RoleEnum::Admin->value))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        expect((float) collect($data['items'])->firstWhere('name', 'Chicken')['quantity'])->toBe(2.0);
    });
});

describe('who can see it', function () {
    it('shows a manager only his own kitchen', function () {
        $otherBranch = Branch::factory()->create();
        $otherLocation = Location::factory()->create([
            'branch_id' => $otherBranch->id, 'type' => 'satellite', 'is_active' => true,
        ]);

        $mine = Order::factory()->create(['branch_id' => $this->branch->id]);
        $theirs = Order::factory()->create(['branch_id' => $otherBranch->id]);
        saleMovement($this->chicken, $this->location, 3, $mine, now()->toDateTimeString());
        saleMovement($this->rice, $otherLocation, 7, $theirs, now()->toDateTimeString());

        $data = $this->actingAs(consumptionStaff(RoleEnum::Manager->value, $this->branch))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertSuccessful()
            ->json('data');

        expect(collect($data['items'])->pluck('name')->all())->toBe(['Chicken']);
    });

    it('refuses a cashier', function () {
        $this->actingAs(consumptionStaff(RoleEnum::SalesStaff->value, $this->branch))
            ->getJson('/v1/inventory/reports/daily-consumption')
            ->assertForbidden();
    });
});
