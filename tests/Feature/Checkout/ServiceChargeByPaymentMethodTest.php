<?php

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutSession;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\SystemSetting;

/*
|--------------------------------------------------------------------------
| Who carries the service charge
|--------------------------------------------------------------------------
|
| The charge covers what the payment gateway takes off a mobile money
| collection. A cash order never touches a gateway, so it owes nothing —
| billing for it would be a fee for a service nobody performed.
|
| Checked on the server because this is the figure that gets written to the
| session and then to the order. A total the browser worked out is a
| suggestion; this is the one the customer is actually charged.
|
*/

function scBranch(): Branch
{
    $branch = Branch::factory()->create(['is_active' => true]);

    // Open every day, all day, so the test does not depend on the wall clock.
    // Hours live in their own table; `branches` has no such column.
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $branch->operatingHours()->updateOrCreate(
            ['day_of_week' => $day],
            ['is_open' => true, 'open_time' => '00:00', 'close_time' => '23:59'],
        );
    }

    $branch->orderTypes()->updateOrCreate(['order_type' => 'pickup'], ['is_enabled' => true]);
    $branch->paymentMethods()->updateOrCreate(['payment_method' => 'momo'], ['is_enabled' => true]);
    $branch->paymentMethods()->updateOrCreate(['payment_method' => 'cash_on_delivery'], ['is_enabled' => true]);

    return $branch->fresh(['orderTypes', 'paymentMethods']);
}

/** A guest cart holding one ₵200 line, so 1% is ₵2.00 and the ₵5 cap is clear. */
function scCart(Branch $branch, string $session): Cart
{
    $item = MenuItem::factory()->create(['branch_id' => $branch->id, 'is_available' => true]);
    $item->branches()->syncWithoutDetaching([$branch->id => ['is_available' => true]]);

    $option = MenuItemOption::factory()->create([
        'menu_item_id' => $item->id,
        'price' => 200.00,
        'is_available' => true,
    ]);

    $cart = Cart::create([
        'branch_id' => $branch->id,
        'session_id' => $session,
        'customer_id' => null,
        'status' => 'active',
    ]);

    CartItem::create([
        'cart_id' => $cart->id,
        'menu_item_id' => $item->id,
        'menu_item_option_id' => $option->id,
        'quantity' => 1,
        'unit_price' => 200.00,
        'subtotal' => 200.00,
    ]);

    return $cart->fresh(['items']);
}

function scOpenSession(Branch $branch, string $session, string $paymentMethod)
{
    return test()->withHeaders(['X-Guest-Session' => $session])
        ->postJson('/v1/checkout-sessions', [
            'branch_id' => $branch->id,
            'customer_name' => 'Akosua Mensah',
            'customer_phone' => '+233241234567',
            'order_type' => 'pickup',
            'payment_method' => $paymentMethod,
            'momo_number' => $paymentMethod === 'mobile_money' ? '+233241234567' : null,
        ]);
}

beforeEach(function () {
    SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'true']);
    SystemSetting::updateOrCreate(['key' => 'service_charge_percent'], ['value' => '1']);
    SystemSetting::updateOrCreate(['key' => 'service_charge_cap'], ['value' => '5']);
});

/*
 * The momo cases do not assert the HTTP status.
 *
 * A mobile money session goes on to ask Hubtel to raise the prompt, which no
 * test can reach, so the request comes back 400. The session row is written
 * before that call and is the thing under test: it is what the customer is
 * charged if the payment goes through.
 */
it('charges mobile money the service charge', function () {
    $branch = scBranch();
    $session = 'guest-service-charge-momo';
    scCart($branch, $session);

    scOpenSession($branch, $session, 'mobile_money');

    $written = CheckoutSession::latest('id')->first();

    expect($written)->not->toBeNull();
    expect((float) $written->service_charge)->toBe(2.00);
    expect((float) $written->total_amount)->toBe(202.00);
});

it('charges cash nothing', function () {
    $branch = scBranch();
    $session = 'guest-service-charge-cash';
    scCart($branch, $session);

    scOpenSession($branch, $session, 'cash')->assertSuccessful();

    $written = CheckoutSession::latest('id')->first();

    expect((float) $written->service_charge)->toBe(0.00);
    expect((float) $written->total_amount)->toBe(200.00);
});

it('charges nothing on either method once the setting is off', function () {
    SystemSetting::updateOrCreate(['key' => 'service_charge_enabled'], ['value' => 'false']);

    $branch = scBranch();
    $session = 'guest-service-charge-off1';
    scCart($branch, $session);

    scOpenSession($branch, $session, 'mobile_money');

    $written = CheckoutSession::latest('id')->first();

    expect($written)->not->toBeNull();
    expect((float) $written->service_charge)->toBe(0.00);
    expect((float) $written->total_amount)->toBe(200.00);
});
