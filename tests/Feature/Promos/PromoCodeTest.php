<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\Promo;
use App\Models\PromoCode;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| Promo codes, at checkout and at the till
|--------------------------------------------------------------------------
|
| A promo without a code applies by itself. A promo with a code applies only
| when somebody types it. An order carries one discount, and the bigger one
| wins, so typing a code never costs the customer money.
|
| Both the checkout and the till ask the same question, of the same service,
| when the basket is shown and again when the order is placed. These tests
| hold the rule, the refusals and their words, and both places an order is
| priced.
|
*/

function pcBranch(string $name = 'Ashaiman'): Branch
{
    $branch = Branch::factory()->create([
        'name' => $name,
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    // Open all day every day, so nothing here depends on the wall clock.
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $branch->operatingHours()->updateOrCreate(
            ['day_of_week' => $day],
            ['is_open' => true, 'open_time' => '00:00', 'close_time' => '23:59'],
        );
    }

    $branch->orderTypes()->updateOrCreate(['order_type' => 'pickup'], ['is_enabled' => true]);
    $branch->paymentMethods()->updateOrCreate(['payment_method' => 'cash_on_delivery'], ['is_enabled' => true]);

    return $branch->fresh(['orderTypes', 'paymentMethods']);
}

/** A dish at the branch with one option at the given price. */
function pcDish(Branch $branch, string $name, float $price): MenuItem
{
    $dish = MenuItem::factory()->create(['branch_id' => $branch->id, 'name' => $name, 'is_available' => true]);
    $dish->branches()->syncWithoutDetaching([$branch->id => ['is_available' => true]]);
    $dish->options()->delete();
    MenuItemOption::factory()->create(['menu_item_id' => $dish->id, 'price' => $price, 'is_available' => true]);

    return $dish->fresh(['options']);
}

/** @param array<string, mixed> $attrs */
function pcPromo(array $attrs = []): Promo
{
    return Promo::create(array_merge([
        'name' => 'Ten off',
        'code' => null,
        'type' => 'fixed_amount',
        'value' => 10,
        'scope' => 'global',
        'applies_to' => 'order',
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'is_active' => true,
    ], $attrs));
}

/** @param array<int, array{0: MenuItem, 1: float}> $lines */
function pcOffer(Branch $branch, array $lines, ?string $code = null, ?string $phone = null)
{
    $payload = [
        'branch_id' => (string) $branch->id,
        'lines' => array_map(fn ($l) => ['menu_item_id' => $l[0]->id, 'amount' => $l[1]], $lines),
        'subtotal' => array_sum(array_map(fn ($l) => $l[1], $lines)),
    ];
    if ($code !== null) {
        $payload['code'] = $code;
    }
    if ($phone !== null) {
        $payload['phone'] = $phone;
    }

    return test()->postJson('/v1/promos/offer', $payload);
}

function pcPriorOrder(?Promo $promo = null, string $phone = '+233241234567', string $status = 'completed'): Order
{
    return Order::factory()->create([
        'promo_id' => $promo?->id,
        'contact_phone' => $phone,
        'status' => $status,
    ]);
}

/** A guest cart holding one ₵100 jollof. */
function pcGuestCart(Branch $branch, string $session): MenuItem
{
    $jollof = pcDish($branch, 'Jollof', 100);
    $cart = Cart::create(['branch_id' => $branch->id, 'session_id' => $session, 'customer_id' => null, 'status' => 'active']);
    CartItem::create([
        'cart_id' => $cart->id,
        'menu_item_id' => $jollof->id,
        'menu_item_option_id' => $jollof->options->first()->id,
        'quantity' => 1,
        'unit_price' => 100,
        'subtotal' => 100,
    ]);

    return $jollof;
}

function pcPlace(Branch $branch, string $session, ?string $code)
{
    return test()->withHeaders(['X-Guest-Session' => $session])
        ->postJson('/v1/checkout-sessions', array_filter([
            'branch_id' => $branch->id,
            'customer_name' => 'Akosua Mensah',
            'customer_phone' => '0241234567',
            'order_type' => 'pickup',
            'payment_method' => 'cash',
            'promo_code' => $code,
        ]));
}

function pcSale(array $extra = [])
{
    return test()->actingAs(test()->cashier)->postJson('/v1/pos/checkout-sessions', array_merge([
        'branch_id' => test()->branch->id,
        'items' => [[
            'menu_item_id' => test()->jollof->id,
            'menu_item_option_id' => test()->jollof->options->first()->id,
            'quantity' => 1,
            'unit_price' => 100,
        ]],
        'payment_method' => 'cash',
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Walk-in',
        'contact_phone' => '0000000000',
    ], $extra));
}

function pcCreate(array $attrs)
{
    return test()->actingAs(test()->admin)->postJson('/v1/promos', array_merge([
        'name' => 'Flyer',
        'type' => 'percentage',
        'value' => 20,
        'scope' => 'global',
        'applies_to' => 'order',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
    ], $attrs));
}

/*
|--------------------------------------------------------------------------
| Which promo, and how much
|--------------------------------------------------------------------------
*/

describe('what comes off a basket', function () {
    it('applies a promo without a code by itself', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['name' => 'Ten off']);

        pcOffer($branch, [[$jollof, 100]])
            ->assertOk()
            ->assertJsonPath('data.promo.name', 'Ten off')
            ->assertJsonPath('data.discount', 10);
    });

    it('never applies a promo with a code unless the code is typed', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['name' => 'Flyer', 'code' => 'CEDI20']);

        pcOffer($branch, [[$jollof, 100]])->assertOk()->assertJsonPath('data.promo', null);

        // The endpoint older clients still call must not hand it out either.
        $this->postJson('/v1/promos/resolve', ['item_ids' => [$jollof->id], 'branch_id' => (string) $branch->id, 'subtotal' => 100])
            ->assertOk()
            ->assertJsonPath('data', null);
    });

    it('applies a code typed in any case, with stray spaces', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['name' => 'Flyer', 'code' => 'cedi20', 'type' => 'percentage', 'value' => 20]);

        expect(Promo::first()->code)->toBe('CEDI20');

        pcOffer($branch, [[$jollof, 100]], ' Cedi20 ')
            ->assertOk()
            ->assertJsonPath('data.promo.code', 'CEDI20')
            ->assertJsonPath('data.discount', 20)
            ->assertJsonPath('data.code_beaten', false);
    });

    it('keeps the bigger offer when the code gives less, and says so', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['name' => 'Twenty percent', 'type' => 'percentage', 'value' => 20]);
        pcPromo(['name' => 'Five off', 'code' => 'FIVE', 'value' => 5]);

        pcOffer($branch, [[$jollof, 100]], 'FIVE')
            ->assertOk()
            ->assertJsonPath('data.promo.name', 'Twenty percent')
            ->assertJsonPath('data.discount', 20)
            ->assertJsonPath('data.code_beaten', true);
    });

    it('takes a dish promo off that dish, not the whole basket', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        $drink = pcDish($branch, 'Kokoo', 20);
        $promo = pcPromo(['code' => 'JOLLOF', 'type' => 'percentage', 'value' => 20, 'applies_to' => 'items']);
        $promo->menuItems()->sync([$jollof->id]);

        // 20% of the ₵100 jollof, not of the ₵120 basket.
        pcOffer($branch, [[$jollof, 100], [$drink, 20]], 'JOLLOF')
            ->assertOk()
            ->assertJsonPath('data.discount', 20);
    });
});

/*
|--------------------------------------------------------------------------
| Refusals, and the words the customer or the cashier reads
|--------------------------------------------------------------------------
*/

describe('a code the order cannot have', function () {
    it('refuses a code that does not exist', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);

        pcOffer($branch, [[$jollof, 100]], 'NOPE')
            ->assertStatus(422)
            ->assertJsonPath('code', 'promo_code_refused')
            ->assertJsonPath('reason', 'not_found')
            ->assertJsonPath('message', 'There is no code NOPE. Check the spelling.');
    });

    it('names the day a code ended', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['code' => 'OLD', 'start_date' => '2026-01-01', 'end_date' => now()->subDays(2)->toDateString()]);

        pcOffer($branch, [[$jollof, 100]], 'OLD')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'ended')
            ->assertJsonPath('message', 'OLD ended on '.now()->subDays(2)->format('j F').'.');
    });

    it('names the branch a code does not cover', function () {
        $ashaiman = pcBranch('Ashaiman');
        $lakeside = pcBranch('Lakeside');
        $jollof = pcDish($ashaiman, 'Jollof', 100);
        $promo = pcPromo(['code' => 'LAKE', 'scope' => 'branch']);
        $promo->branches()->sync([$lakeside->id]);

        pcOffer($ashaiman, [[$jollof, 100]], 'LAKE')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'branch')
            ->assertJsonPath('message', 'LAKE cannot be used at Ashaiman.');
    });

    it('states the order value a code needs', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 60);
        pcPromo(['code' => 'BIG', 'min_order_value' => 100]);

        pcOffer($branch, [[$jollof, 60]], 'BIG')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'min_order')
            ->assertJsonPath('message', 'BIG needs an order of ₵100.00 or more.');
    });

    it('names the dish a code is for when it is not on the order', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof Rice', 100);
        $wrap = pcDish($branch, 'Cedi Wrap', 60);
        $promo = pcPromo(['code' => 'JOLLOF', 'applies_to' => 'items']);
        $promo->menuItems()->sync([$jollof->id]);

        pcOffer($branch, [[$wrap, 60]], 'JOLLOF')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'items')
            ->assertJsonPath('message', 'JOLLOF is for Jollof Rice. Add one to the order to use it.');
    });

    it('stops a code once it is used up, and a cancelled order gives its use back', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        $promo = pcPromo(['code' => 'FIRST50', 'max_uses' => 1]);
        $used = pcPriorOrder($promo, '+233201111111');

        pcOffer($branch, [[$jollof, 100]], 'FIRST50')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'used_up');

        $used->update(['status' => 'cancelled']);

        pcOffer($branch, [[$jollof, 100]], 'FIRST50')->assertOk()->assertJsonPath('data.discount', 10);
    });

    it('holds a code to its uses per phone number, in either format', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        $promo = pcPromo(['code' => 'ONCE', 'max_uses_per_customer' => 1]);

        // Stored the old way, typed the new way. Still the same person.
        pcPriorOrder($promo, '0241234567');

        pcOffer($branch, [[$jollof, 100]], 'ONCE', '+233241234567')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'per_customer')
            ->assertJsonPath('message', 'This phone number has already used ONCE.');

        pcOffer($branch, [[$jollof, 100]], 'ONCE', '0551234567')->assertOk();
    });

    it('keeps a first-order code for people who have not ordered', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['code' => 'WELCOME', 'first_order_only' => true]);
        pcPriorOrder(null, '+233241234567');

        pcOffer($branch, [[$jollof, 100]], 'WELCOME', '0241234567')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'first_order');

        pcOffer($branch, [[$jollof, 100]], 'WELCOME', '0551234567')->assertOk();
    });

    it('asks for a phone number before a code that depends on the customer', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['code' => 'WELCOME', 'first_order_only' => true]);

        // The till's walk-in placeholder is not a person.
        pcOffer($branch, [[$jollof, 100]], 'WELCOME', '0000000000')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'needs_phone')
            ->assertJsonPath('message', "WELCOME depends on who is ordering. Add the customer's phone number first.");
    });

    it('slows down somebody guessing codes, but never a till re-checking a good one', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcPromo(['code' => 'GOOD']);

        foreach (range(1, 15) as $_) {
            pcOffer($branch, [[$jollof, 100]], 'GOOD')->assertOk();
        }

        foreach (range(1, 10) as $i) {
            pcOffer($branch, [[$jollof, 100]], "GUESS{$i}")->assertStatus(422);
        }

        pcOffer($branch, [[$jollof, 100]], 'GUESS11')
            ->assertStatus(429)
            ->assertJsonPath('reason', 'too_many');
    });
});

/*
|--------------------------------------------------------------------------
| The customer checkout
|--------------------------------------------------------------------------
*/

describe('the online checkout', function () {
    beforeEach(function () {
        SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'false']);
    });

    it('writes the code onto the order the customer places', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-promo-code-applied');
        $promo = pcPromo(['name' => 'Flyer', 'code' => 'CEDI20', 'type' => 'percentage', 'value' => 20]);

        pcPlace($branch, 'guest-promo-code-applied', 'cedi20')->assertSuccessful();

        $session = CheckoutSession::latest('id')->first();
        expect((float) $session->discount)->toBe(20.00);
        expect((float) $session->total_amount)->toBe(80.00);
        expect($session->promo_id)->toBe($promo->id);

        $order = Order::latest('id')->first();
        expect($order->promo_id)->toBe($promo->id);
        expect((float) $order->discount)->toBe(20.00);
    });

    it('refuses the order rather than drop a code the customer was shown', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-promo-code-refused');
        $promo = pcPromo(['code' => 'ONCE', 'max_uses_per_customer' => 1]);
        pcPriorOrder($promo, '+233241234567');

        pcPlace($branch, 'guest-promo-code-refused', 'ONCE')
            ->assertStatus(422)
            ->assertJsonPath('code', 'promo_code_refused')
            ->assertJsonPath('reason', 'per_customer');

        expect(CheckoutSession::count())->toBe(0);
    });

    it('lets somebody who checked a few codes still place the order', function () {
        // A bare `throttle:5,1` keys on the IP and not the route, so the offer
        // calls a checkout makes on the way to the review used to use up the
        // five order attempts, and "Place order" answered 429.
        $branch = pcBranch();
        $jollof = pcGuestCart($branch, 'guest-promo-throttle-shared');
        pcPromo(['code' => 'CEDI20', 'type' => 'percentage', 'value' => 20]);

        foreach (range(1, 8) as $_) {
            pcOffer($branch, [[$jollof, 100]], 'CEDI20')->assertOk();
        }

        pcPlace($branch, 'guest-promo-throttle-shared', 'CEDI20')->assertSuccessful();
    });

    it('still gives the automatic promo to an order with no code', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-promo-automatic');
        pcPromo(['name' => 'Ten off']);

        pcPlace($branch, 'guest-promo-automatic', null)->assertSuccessful();

        expect((float) CheckoutSession::latest('id')->first()->discount)->toBe(10.00);
    });
});

/*
|--------------------------------------------------------------------------
| The till
|--------------------------------------------------------------------------
*/

describe('the till', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = pcBranch();
        $this->jollof = pcDish($this->branch, 'Jollof', 100);

        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $employee->branches()->attach($this->branch);
        $user->syncRoles([RoleEnum::SalesStaff->value]);
        $this->cashier = $user->fresh();
    });

    it('takes a code typed at the till', function () {
        $promo = pcPromo(['code' => 'CEDI20', 'type' => 'percentage', 'value' => 20]);

        pcSale(['promo_code' => 'CEDI20'])->assertSuccessful();

        $session = CheckoutSession::latest('id')->first();
        expect((float) $session->discount)->toBe(20.00);
        expect((float) $session->total_amount)->toBe(80.00);
        expect($session->promo_id)->toBe($promo->id);
    });

    it('works the discount out itself and ignores what an older till sends', function () {
        pcPromo(['name' => 'Ten off']);

        pcSale(['discount' => 50])->assertSuccessful();

        expect((float) CheckoutSession::latest('id')->first()->discount)->toBe(10.00);
    });

    it('asks the cashier for the phone number before a once-per-customer code', function () {
        pcPromo(['code' => 'ONCE', 'max_uses_per_customer' => 1]);

        pcSale(['promo_code' => 'ONCE'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'needs_phone');

        pcSale(['promo_code' => 'ONCE', 'contact_phone' => '0241234567'])->assertSuccessful();
    });
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

describe('setting up a code', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        Employee::factory()->create(['user_id' => $this->admin->id, 'status' => EmployeeStatus::Active]);
        $this->admin->syncRoles([RoleEnum::Admin->value]);
    });

    it('stores a code in capitals, with its limits', function () {
        pcCreate(['code' => 'cedi 20', 'max_uses' => 100, 'max_uses_per_customer' => 1, 'first_order_only' => true])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CEDI20')
            ->assertJsonPath('data.maxUses', 100)
            ->assertJsonPath('data.maxUsesPerCustomer', 1)
            ->assertJsonPath('data.firstOrderOnly', true);
    });

    it('refuses a code another live promo already has, and frees it once that promo is deleted', function () {
        $first = pcPromo(['code' => 'CEDI20']);

        pcCreate(['code' => 'Cedi20'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Another promo already uses that code.');

        $first->delete();

        pcCreate(['code' => 'CEDI20'])->assertCreated();
    });

    it('counts the uses on the list', function () {
        $promo = pcPromo(['code' => 'CEDI20']);
        pcPriorOrder($promo, '+233241234567');
        pcPriorOrder($promo, '+233551234567');
        pcPriorOrder($promo, '+233201234567', 'cancelled');

        $this->actingAs($this->admin)->getJson('/v1/promos')
            ->assertOk()
            ->assertJsonPath('data.0.timesUsed', 2);
    });
});

/*
|--------------------------------------------------------------------------
| One-off codes
|--------------------------------------------------------------------------
|
| A batch of vouchers for one promo, each good for one order. The promo says
| it is one of these in `redemption`, so a batch not yet made cannot leave it
| looking like a promo that applies to everybody.
|
*/

/** A one-off promo with codes already made, as the admin screen would make them. */
function pcSingleUse(array $attrs = [], array $codes = ['JOLLOF-K7Q2MX']): Promo
{
    $promo = pcPromo(array_merge(['name' => 'Radio giveaway', 'redemption' => Promo::SINGLE_USE, 'value' => 25], $attrs));
    foreach ($codes as $code) {
        PromoCode::create(['promo_id' => $promo->id, 'code' => $code, 'lookup' => PromoCode::lookupKey($code)]);
    }

    return $promo;
}

describe('a one-off code', function () {
    beforeEach(function () {
        SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'false']);
    });

    it('never applies by itself, even before any codes are made', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcSingleUse(codes: []);

        pcOffer($branch, [[$jollof, 100]])->assertOk()->assertJsonPath('data.promo', null);
    });

    it('applies once, typed any way, and shows as it is written', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        pcSingleUse();

        pcOffer($branch, [[$jollof, 100]], 'jollofk7q2mx')
            ->assertOk()
            ->assertJsonPath('data.promo.name', 'Radio giveaway')
            ->assertJsonPath('data.discount', 25)
            ->assertJsonPath('data.applied_code', 'JOLLOF-K7Q2MX');
    });

    it('is spent by the order that uses it, and a cancelled order gives it back', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-single-use-spent');
        $promo = pcSingleUse();
        $code = PromoCode::first();

        pcPlace($branch, 'guest-single-use-spent', 'JOLLOF-K7Q2MX')->assertSuccessful();

        $order = Order::latest('id')->first();
        expect($order->promo_code_id)->toBe($code->id);
        expect($order->promo_id)->toBe($promo->id);
        expect((float) $order->discount)->toBe(25.00);

        $jollof = MenuItem::where('name', 'Jollof')->first();
        pcOffer($branch, [[$jollof, 100]], 'JOLLOF-K7Q2MX', '0551234567')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'code_used')
            ->assertJsonPath('message', 'JOLLOF-K7Q2MX has already been used.');

        $order->update(['status' => 'cancelled']);

        pcOffer($branch, [[$jollof, 100]], 'JOLLOF-K7Q2MX', '0551234567')->assertOk();
    });

    it('is held by somebody else paying with it, but never against its own number', function () {
        $branch = pcBranch();
        $jollof = pcDish($branch, 'Jollof', 100);
        $promo = pcSingleUse();

        // A Mobile Money payment in progress on another phone, five minutes long.
        CheckoutSession::create([
            'session_token' => 'held-by-another-phone',
            'branch_id' => $branch->id,
            'session_type' => 'online',
            'status' => 'payment_initiated',
            'customer_name' => 'Kofi',
            'customer_phone' => '+233241234567',
            'fulfillment_type' => 'pickup',
            'payment_method' => 'mobile_money',
            'items' => [],
            'subtotal' => 100,
            'service_charge' => 0,
            'delivery_fee' => 0,
            'discount' => 25,
            'promo_id' => $promo->id,
            'promo_code_id' => PromoCode::first()->id,
            'total_amount' => 75,
            'expires_at' => now()->addMinutes(5),
        ]);

        pcOffer($branch, [[$jollof, 100]], 'JOLLOF-K7Q2MX', '0551234567')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'code_used');

        // Kofi going back to change something still has his own voucher.
        pcOffer($branch, [[$jollof, 100]], 'JOLLOF-K7Q2MX', '0241234567')->assertOk();
    });

    it('is taken at the till and written onto the sale', function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = pcBranch();
        $this->jollof = pcDish($this->branch, 'Jollof', 100);
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $employee->branches()->attach($this->branch);
        $user->syncRoles([RoleEnum::SalesStaff->value]);
        $this->cashier = $user->fresh();

        pcSingleUse();

        pcSale(['promo_code' => 'jollof-k7q2mx'])->assertSuccessful();

        $session = CheckoutSession::latest('id')->first();
        expect($session->promo_code_id)->toBe(PromoCode::first()->id);
        expect((float) $session->discount)->toBe(25.00);
    });
});

describe('making one-off codes', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        Employee::factory()->create(['user_id' => $this->admin->id, 'status' => EmployeeStatus::Active]);
        $this->admin->syncRoles([RoleEnum::Admin->value]);
    });

    it('makes a batch under a prefix, every code different and easy to read', function () {
        $promo = pcSingleUse(codes: []);

        $made = $this->actingAs($this->admin)
            ->postJson("/v1/promos/{$promo->id}/codes", ['count' => 50, 'prefix' => 'jollof', 'batch' => 'Radio'])
            ->assertCreated()
            ->json('data');

        expect($made)->toHaveCount(50);
        $codes = array_column($made, 'code');
        expect(array_unique($codes))->toHaveCount(50);
        foreach ($codes as $code) {
            expect($code)->toMatch('/^JOLLOF-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/');
        }
        expect(PromoCode::where('batch', 'Radio')->count())->toBe(50);
    });

    it('lists each code with the order that used it', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-single-use-list');
        $promo = pcSingleUse(codes: ['A-AAAAAA', 'B-BBBBBB']);
        SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'false']);
        pcPlace($branch, 'guest-single-use-list', 'A-AAAAAA')->assertSuccessful();
        $number = Order::latest('id')->value('order_number');

        $rows = $this->actingAs($this->admin)->getJson("/v1/promos/{$promo->id}/codes")->assertOk()->json('data');

        expect($rows[0])->toMatchArray(['code' => 'A-AAAAAA', 'used' => true, 'orderNumber' => $number]);
        expect($rows[1])->toMatchArray(['code' => 'B-BBBBBB', 'used' => false, 'orderNumber' => null]);

        $this->actingAs($this->admin)->getJson("/v1/promos/{$promo->id}")
            ->assertJsonPath('data.codesCount', 2)
            ->assertJsonPath('data.codesUsed', 1);
    });

    it('takes back an unused code but keeps a used one on record', function () {
        $branch = pcBranch();
        pcGuestCart($branch, 'guest-single-use-delete');
        $promo = pcSingleUse(codes: ['A-AAAAAA', 'B-BBBBBB']);
        SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'false']);
        pcPlace($branch, 'guest-single-use-delete', 'A-AAAAAA')->assertSuccessful();
        [$used, $unused] = PromoCode::orderBy('id')->get();

        $this->actingAs($this->admin)->deleteJson("/v1/promos/{$promo->id}/codes/{$used->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A-AAAAAA was used on order '.Order::latest('id')->value('order_number').', so it stays on record.');

        $this->actingAs($this->admin)->deleteJson("/v1/promos/{$promo->id}/codes/{$unused->id}")->assertSuccessful();
        expect(PromoCode::count())->toBe(1);
    });

    it('refuses a batch for a promo that is not given out as one-off codes', function () {
        $promo = pcPromo(['code' => 'CEDI20']);

        $this->actingAs($this->admin)->postJson("/v1/promos/{$promo->id}/codes", ['count' => 5])->assertStatus(422);
    });

    it('will not let a shared code be the same word as a one-off code', function () {
        pcSingleUse(codes: ['CEDI-20']);

        pcCreate(['redemption' => 'shared_code', 'code' => 'cedi20'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'That is already one of the one-off codes.');
    });

    it('drops the code when a promo is switched to apply by itself', function () {
        $promo = pcPromo(['code' => 'CEDI20']);
        expect($promo->redemption)->toBe(Promo::SHARED_CODE);

        $this->actingAs($this->admin)->patchJson("/v1/promos/{$promo->id}", ['redemption' => 'automatic'])
            ->assertOk()
            ->assertJsonPath('data.redemption', 'automatic')
            ->assertJsonPath('data.code', null);
    });
});
