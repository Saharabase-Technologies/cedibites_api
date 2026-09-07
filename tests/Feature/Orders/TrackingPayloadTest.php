<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Who is allowed to see the address
|--------------------------------------------------------------------------
|
| `GET /orders/by-number/{code}` is public and throttled, because the person
| chasing an order has usually not signed in and is reading the code off an
| SMS. An order code is one or two letters and three digits, so a prefix holds
| 999 of them and the live cycle can be walked in about an hour. Everything in
| that payload has to survive being handed to somebody who guessed.
|
| So the stage, the money and the branch's own public contact details are for
| everybody, and the delivery address and the contact name are for two people:
| whoever holds the tracking link we texted, and whoever is signed in to the
| account the order belongs to. The second is new. A customer looking at an
| order placed under their own account was being told the address is only on
| the tracking link — about an address they typed in themselves.
|
*/

function trackedOrder(array $attributes = []): Order
{
    $branch = Branch::factory()->create();

    return Order::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'order_type' => 'delivery',
        'delivery_address' => '12 Nii Tetteh Amui Street, Tema',
        'contact_name' => 'Akosua Mensah',
    ], $attributes));
}

it('hides the address from somebody who only guessed the code', function () {
    $order = trackedOrder();

    $response = $this->getJson("/v1/orders/by-number/{$order->order_number}");

    $response->assertOk();
    expect($response->json('data.delivery_address'))->toBeNull();
    expect($response->json('data.contact_name'))->toBeNull();
    // The half everybody gets is still there.
    expect($response->json('data.status'))->toBe($order->status);
});

it('shows the address to whoever holds the tracking link', function () {
    $order = trackedOrder();

    $response = $this->getJson(
        "/v1/orders/by-number/{$order->order_number}?t={$order->trackingToken()}"
    );

    $response->assertOk();
    expect($response->json('data.delivery_address'))->toBe('12 Nii Tetteh Amui Street, Tema');
    expect($response->json('data.contact_name'))->toBe('Akosua Mensah');
});

it('rejects a token that belongs to a different order', function () {
    $mine = trackedOrder();
    $theirs = trackedOrder();

    $response = $this->getJson(
        "/v1/orders/by-number/{$mine->order_number}?t={$theirs->trackingToken()}"
    );

    $response->assertOk();
    expect($response->json('data.delivery_address'))->toBeNull();
});

it('shows the address to the signed-in customer the order belongs to', function () {
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id]);
    $order = trackedOrder(['customer_id' => $customer->id]);

    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson("/v1/orders/by-number/{$order->order_number}");

    $response->assertOk();
    expect($response->json('data.delivery_address'))->toBe('12 Nii Tetteh Amui Street, Tema');
});

it('hides the address from a signed-in customer the order does not belong to', function () {
    $owner = Customer::factory()->create();
    $order = trackedOrder(['customer_id' => $owner->id]);

    $someoneElse = User::factory()->create();
    Customer::factory()->create(['user_id' => $someoneElse->id]);

    Sanctum::actingAs($someoneElse, ['customer']);

    $response = $this->getJson("/v1/orders/by-number/{$order->order_number}");

    $response->assertOk();
    expect($response->json('data.delivery_address'))->toBeNull();
});

it('carries the live option so a line can be named on the receipt', function () {
    $order = trackedOrder();

    $item = MenuItem::factory()->create(['branch_id' => $order->branch_id]);
    $option = MenuItemOption::factory()->create([
        'menu_item_id' => $item->id,
        'option_key' => 'fried-rice',
        'option_label' => 'Fried Rice',
        'display_name' => 'Assorted Fried Rice + Full Chicken + Kɔkɔɔ',
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $item->id,
        'menu_item_option_id' => $option->id,
        // An order placed before receipt names existed: the snapshot has none,
        // which is the case this fallback is for.
        'menu_item_option_snapshot' => [
            'id' => $option->id,
            'option_key' => 'fried-rice',
            'option_label' => 'Fried Rice',
            'display_name' => null,
            'price' => 250.0,
            'image_url' => null,
        ],
    ]);

    $response = $this->getJson("/v1/orders/by-number/{$order->order_number}");

    $response->assertOk();
    expect($response->json('data.items.0.menu_item_option.display_name'))
        ->toBe('Assorted Fried Rice + Full Chicken + Kɔkɔɔ');
    expect($response->json('data.items.0.menu_item_option_snapshot.display_name'))->toBeNull();
});
