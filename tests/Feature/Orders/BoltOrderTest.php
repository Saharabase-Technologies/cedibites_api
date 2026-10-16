<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\User;
use App\Services\Analytics\AnalyticsQueryBuilder;
use App\Services\Bolt\BoltSettings;
use App\Services\Payments\ShiftMoney;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Bolt Food orders, rung at the till (2026-10-16)
|--------------------------------------------------------------------------
|
| Each dish has a Bolt price and sometimes a Bolt promo, set on the menu's
| Bolt page. A Bolt order is written at those prices, the promo is its
| discount, the customer paid Bolt, and Bolt keeps its cut. Net sales count
| only what we keep (the owner, 2026-10-14).
|
*/

function boStaff(Branch $branch, string $name, string $role = RoleEnum::SalesStaff->value): array
{
    $user = User::factory()->create(['name' => $name]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
    $employee->branches()->attach($branch);
    $user->syncRoles([$role]);

    return ['user' => $user->fresh(), 'employee' => $employee];
}

/** A dish served at the branch, its one option on Bolt at the price given. */
function boDish(Branch $branch, string $name, float $price, ?float $boltPrice, ?float $promo = null): MenuItemOption
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name]);
    $dish->branches()->attach($branch->id, ['is_available' => true]);

    $option = $dish->options()->firstOrFail();
    $option->forceFill(['price' => $price, 'bolt_price' => $boltPrice, 'bolt_promo_percent' => $promo])->save();

    return $option->fresh();
}

/** Rosina rings a Bolt order off the tablet. */
function boRing(User $cashier, Branch $branch, array $lines, array $overrides = [])
{
    return test()->actingAs($cashier)->postJson('/v1/pos/checkout-sessions', array_merge([
        'branch_id' => $branch->id,
        'items' => array_map(fn (array $line) => [
            'menu_item_id' => $line[0]->menu_item_id,
            'menu_item_option_id' => $line[0]->id,
            'quantity' => $line[1],
            // The till's figure is never trusted. This is our counter price,
            // to prove the server charges Bolt's.
            'unit_price' => (float) $line[0]->price,
        ], $lines),
        'payment_method' => 'bolt',
        'order_source' => 'bolt',
        'platform_reference' => 'wwj 98',
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Bolt customer',
        'contact_phone' => '0000000000',
    ], $overrides));
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

    ['user' => $this->rosina, 'employee' => $this->rosinaEmployee] = boStaff($this->branch, 'Rosina');
    ['user' => $this->admin] = boStaff($this->branch, 'Owner', RoleEnum::Admin->value);

    // A combo: 75 at our counter, 93.75 on Bolt with 20% off that we pay.
    $this->combo = boDish($this->branch, 'Economy Pack', 75, 93.75, 20);
    // A wrap: 70 at our counter, 80 on Bolt, no promo.
    $this->wrap = boDish($this->branch, 'Mixed Wrap', 70, 80);
    // Water is not on Bolt.
    $this->water = boDish($this->branch, 'Water', 10, null);
});

describe('the menu\'s Bolt page', function () {
    it('shows each dish with our price beside its Bolt price and promo', function () {
        $board = test()->actingAs($this->admin)->getJson('/v1/admin/bolt')->assertOk()->json('data');

        $options = collect($board['dishes'])->flatMap(fn ($d) => $d['options'])->keyBy('id');

        expect($board['settings'])->toEqual(['commission_percent' => 30, 'commission_base' => 'paid'])
            ->and($board['on_bolt_count'])->toBe(2)
            ->and($options[$this->combo->id]['price'])->toEqual(75)
            ->and($options[$this->combo->id]['bolt_price'])->toEqual(93.75)
            ->and($options[$this->combo->id]['bolt_promo_percent'])->toEqual(20)
            ->and($options[$this->water->id]['bolt_price'])->toBeNull();
    });

    it('sets a Bolt price and promo at once, and takes a dish off Bolt', function () {
        test()->actingAs($this->admin)
            ->putJson("/v1/admin/bolt/options/{$this->water->id}", ['bolt_price' => 13, 'bolt_promo_percent' => null])
            ->assertOk();

        expect((float) $this->water->fresh()->bolt_price)->toBe(13.0);

        test()->actingAs($this->admin)
            ->putJson("/v1/admin/bolt/options/{$this->combo->id}", ['bolt_price' => null, 'bolt_promo_percent' => 20])
            ->assertOk();

        // Off Bolt, the promo goes with it.
        expect($this->combo->fresh()->bolt_price)->toBeNull()
            ->and($this->combo->fresh()->bolt_promo_percent)->toBeNull();
    });

    it('tells open tills to reload their menu when a Bolt price changes', function () {
        \Illuminate\Support\Facades\Event::fake([\App\Events\MenuUpdatedEvent::class]);

        test()->actingAs($this->admin)
            ->putJson("/v1/admin/bolt/options/{$this->wrap->id}", ['bolt_price' => 85, 'bolt_promo_percent' => null])
            ->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\MenuUpdatedEvent::class, fn ($e) => $e->kind === 'bolt');
    });

    it('sets what Bolt keeps, and what it takes it from', function () {
        test()->actingAs($this->admin)
            ->putJson('/v1/admin/bolt/settings', ['commission_percent' => 25, 'commission_base' => 'listed'])
            ->assertOk()
            ->assertJsonPath('data.settings.commission_percent', 25)
            ->assertJsonPath('data.settings.commission_base', 'listed');

        test()->actingAs($this->admin)
            ->putJson('/v1/admin/bolt/settings', ['commission_percent' => 120, 'commission_base' => 'paid'])
            ->assertUnprocessable();
    });

    it('is not for a cashier', function () {
        test()->actingAs($this->rosina)->getJson('/v1/admin/bolt')->assertForbidden();
        test()->actingAs($this->rosina)
            ->putJson("/v1/admin/bolt/options/{$this->water->id}", ['bolt_price' => 1, 'bolt_promo_percent' => null])
            ->assertForbidden();
    });

    it('sends the Bolt price with the menu, so the till can show it', function () {
        $option = test()->getJson("/v1/menu-items/{$this->combo->menu_item_id}")
            ->assertOk()
            ->json('data.options.0');

        expect($option['bolt_price'])->toEqual(93.75)
            ->and($option['bolt_promo_percent'])->toEqual(20);
    });
});

describe('ringing a Bolt order', function () {
    it('charges Bolt\'s prices, takes the promo off, and keeps Bolt\'s cut apart', function () {
        // Two combos and a wrap: 187.50 + 80 = 267.50 listed on Bolt. The promo
        // is 20% of 187.50 = 37.50, so the customer paid Bolt 230. Bolt keeps
        // 30% of 230 = 69, and we keep 161.
        $response = boRing($this->rosina, $this->branch, [[$this->combo, 2], [$this->wrap, 1]])
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('order.order_source', 'bolt')
            ->assertJsonPath('order.platform_reference', 'WWJ98')
            ->assertJsonPath('order.promo_name', 'Bolt promo');

        $order = Order::with('payments', 'items')->findOrFail($response->json('order.id'));

        expect((float) $order->subtotal)->toBe(267.5)
            ->and((float) $order->discount)->toBe(37.5)
            ->and((float) $order->total_amount)->toBe(230.0)
            ->and((float) $order->delivery_fee)->toBe(0.0)
            ->and((float) $order->platform_commission)->toBe(69.0)
            ->and((float) $order->platform_commission_rate)->toBe(30.0)
            ->and($order->platform_commission_base)->toBe('paid')
            ->and($order->order_type)->toBe('pickup')
            ->and($order->assigned_employee_id)->toBe($this->rosinaEmployee->id)
            ->and($order->items->firstWhere('menu_item_option_id', $this->combo->id)->unit_price)->toEqual(93.75)
            ->and($order->items->firstWhere('menu_item_option_id', $this->wrap->id)->unit_price)->toEqual(80)
            ->and((float) $response->json('order.net_amount'))->toBe(161.0);

        $payment = $order->payments->sole();

        expect($payment->payment_method)->toBe('bolt')
            ->and($payment->payment_status)->toBe('completed')
            ->and((float) $payment->amount)->toBe(230.0)
            // Bolt holds the money. Nobody on staff took it.
            ->and($payment->collected_by)->toBeNull()
            ->and($payment->shift_id)->toBeNull()
            ->and($payment->source)->toBe('bolt');
    });

    it('takes Bolt\'s cut from the listed price when the setting says so', function () {
        app(BoltSettings::class)->update(30, BoltSettings::BASE_LISTED);

        $response = boRing($this->rosina, $this->branch, [[$this->combo, 2], [$this->wrap, 1]])->assertCreated();
        $order = Order::findOrFail($response->json('order.id'));

        // 30% of 267.50 = 80.25.
        expect((float) $order->platform_commission)->toBe(80.25)
            ->and($order->platform_commission_base)->toBe('listed');
    });

    it('counts only what we keep in net sales', function () {
        boRing($this->rosina, $this->branch, [[$this->combo, 2], [$this->wrap, 1]])->assertCreated();

        expect(app(AnalyticsQueryBuilder::class)->computeRevenue([]))->toBe(161.0);
    });

    it('puts nothing in anyone\'s money for the shift', function () {
        boRing($this->rosina, $this->branch, [[$this->wrap, 1]])->assertCreated();

        $money = app(ShiftMoney::class)->forCollector($this->rosinaEmployee->id, now()->subHour(), now()->addHour());

        expect(collect($money)->sum('amount'))->toEqual(0);
    });

    it('refuses a dish that has no Bolt price', function () {
        boRing($this->rosina, $this->branch, [[$this->wrap, 1], [$this->water, 1]])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'not_on_bolt');

        expect(Order::count())->toBe(0);
    });

    it('refuses the same Bolt code twice at a branch', function () {
        $first = boRing($this->rosina, $this->branch, [[$this->wrap, 1]])->assertCreated();

        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], ['platform_reference' => 'WWJ98'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bolt_duplicate')
            ->assertJsonPath('message', 'Bolt order WWJ98 is already on the till as #'.$first->json('order.order_number').'.');
    });

    it('needs the Bolt code', function () {
        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], ['platform_reference' => null])
            ->assertUnprocessable();

        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], ['platform_reference' => 'W!'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bolt_reference');
    });

    it('is paid on Bolt and nothing else, and Paid on Bolt is for Bolt orders only', function () {
        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], ['payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bolt_refused');

        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], ['order_source' => 'pos'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bolt_refused');
    });

    it('is not recorded afterwards as a past order', function () {
        boRing($this->rosina, $this->branch, [[$this->wrap, 1]], [
            'is_manual_entry' => true,
            'recorded_at' => now()->subHour()->toIso8601String(),
            'manual_entry_reason' => 'Power cut',
        ])->assertUnprocessable()->assertJsonPath('code', 'bolt_refused');
    });

    it('carries no delivery fee', function () {
        $response = boRing($this->rosina, $this->branch, [[$this->wrap, 1]], [
            'fulfillment_type' => 'delivery',
            'delivery_fee' => 15,
        ])->assertCreated();

        $order = Order::findOrFail($response->json('order.id'));

        expect((float) $order->delivery_fee)->toBe(0.0)
            ->and($order->order_type)->toBe('pickup')
            ->and((float) $order->total_amount)->toBe(80.0);
    });
});

describe('Finance', function () {
    it('shows Bolt\'s cut on the sales row, and item sales at our share', function () {
        boRing($this->rosina, $this->branch, [[$this->combo, 2], [$this->wrap, 1]])->assertCreated();

        $data = test()->actingAs($this->admin)->getJson('/v1/admin/finance/ledger')->assertOk()->json('data');

        $row = $data['sales'][0];

        // gross 267.50, discount 37.50, service 0, net 161, delivery 0, Bolt 69.
        expect(array_slice($row, 2))->toEqual(['bolt', 'pickup', 'bolt', 1, 267.5, 37.5, 0, 161, 0, 69]);

        // Each line's share of the 161: 187.50 and 80 scaled by 161 / 267.50.
        $items = collect($data['items'])->keyBy(5);

        expect($items[$this->combo->id][7])->toEqual(112.85)
            ->and($items[$this->wrap->id][7])->toEqual(48.15);
    });
});
