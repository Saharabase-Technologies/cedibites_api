<?php

use App\Models\Address;
use App\Models\Customer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| The addresses a customer has saved
|--------------------------------------------------------------------------
|
| The `addresses` table has existed since February with no controller, no route
| and no rows anywhere. Checkout kept the last address in localStorage instead,
| which is one browser on one device.
|
| The rule that matters most here is scoping. Every lookup is filtered by the
| caller's own customer row before the id in the path is considered, so another
| customer's address is not found rather than found-and-refused. A 403 would
| confirm the id exists.
|
*/

/** @return array{user: User, customer: Customer} */
function addrCustomer(): array
{
    $user = User::factory()->create();
    $customer = Customer::factory()->create(['user_id' => $user->id, 'is_guest' => false]);

    return ['user' => $user->fresh('customer'), 'customer' => $customer];
}

function addrPost(array $overrides = [])
{
    return test()->postJson('/v1/addresses', array_merge([
        'full_address' => '17 Alhaji Sulley Road, Abelenkpe, Accra',
    ], $overrides));
}

it('saves an address and makes the first one the default', function () {
    ['user' => $user] = addrCustomer();
    Sanctum::actingAs($user, ['customer']);

    $response = addrPost(['label' => 'Home'])->assertCreated();

    expect($response->json('data.label'))->toBe('Home');
    expect($response->json('data.is_default'))->toBeTrue();
});

it('keeps exactly one default', function () {
    ['user' => $user] = addrCustomer();
    Sanctum::actingAs($user, ['customer']);

    addrPost(['full_address' => 'First Street, Accra'])->assertCreated();
    $second = addrPost(['full_address' => 'Second Street, Accra', 'is_default' => true])->assertCreated();

    $defaults = Address::query()->where('is_default', true)->pluck('id');

    expect($defaults)->toHaveCount(1);
    expect($defaults->first())->toBe($second->json('data.id'));
});

it('does not save the same address twice', function () {
    ['user' => $user] = addrCustomer();
    Sanctum::actingAs($user, ['customer']);

    addrPost(['label' => 'Home'])->assertCreated();
    // Checkout saves after every order, so the same door arriving again — in a
    // different case, with surrounding space — must land on the same row.
    addrPost(['full_address' => '  17 alhaji sulley road, Abelenkpe, Accra '])->assertOk();

    expect(Address::query()->count())->toBe(1);
    // And the automatic save must not wipe a label the customer typed.
    expect(Address::query()->first()->label)->toBe('Home');
    // Nor rewrite the address itself in whatever casing the later call carried.
    // The customer typed it properly the first time.
    expect(Address::query()->first()->full_address)->toBe('17 Alhaji Sulley Road, Abelenkpe, Accra');
});

it('lists the default first', function () {
    ['user' => $user] = addrCustomer();
    Sanctum::actingAs($user, ['customer']);

    addrPost(['full_address' => 'First Street, Accra'])->assertCreated();
    addrPost(['full_address' => 'Second Street, Accra'])->assertCreated();
    addrPost(['full_address' => 'Third Street, Accra', 'is_default' => true])->assertCreated();

    $listed = test()->getJson('/v1/addresses')->assertOk()->json('data');

    expect($listed)->toHaveCount(3);
    expect($listed[0]['full_address'])->toBe('Third Street, Accra');
    expect($listed[0]['is_default'])->toBeTrue();
});

it('never shows one customer another customer address', function () {
    ['customer' => $theirs] = addrCustomer();
    $hidden = Address::factory()->create([
        'customer_id' => $theirs->id,
        'full_address' => 'Somebody Else Street, Accra',
    ]);

    ['user' => $me] = addrCustomer();
    Sanctum::actingAs($me, ['customer']);

    expect(test()->getJson('/v1/addresses')->assertOk()->json('data'))->toHaveCount(0);

    // Not found, not forbidden: a 403 would confirm the id is real.
    test()->patchJson("/v1/addresses/{$hidden->id}", ['label' => 'Mine now'])->assertNotFound();
    test()->deleteJson("/v1/addresses/{$hidden->id}")->assertNotFound();

    expect($hidden->fresh()->label)->not->toBe('Mine now');
});

it('promotes another address when the default is deleted', function () {
    ['user' => $user] = addrCustomer();
    Sanctum::actingAs($user, ['customer']);

    $first = addrPost(['full_address' => 'First Street, Accra'])->assertCreated();
    addrPost(['full_address' => 'Second Street, Accra'])->assertCreated();

    test()->deleteJson("/v1/addresses/{$first->json('data.id')}")->assertOk();

    $remaining = Address::query()->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->is_default)->toBeTrue();
});

it('turns a guest away', function () {
    test()->getJson('/v1/addresses')->assertUnauthorized();
    addrPost()->assertUnauthorized();
});
