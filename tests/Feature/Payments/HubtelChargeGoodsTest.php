<?php

use App\Models\Order;
use App\Services\HubtelPaymentService;

/**
 * The restaurant charges the GOODS amount only — the third-party delivery fee
 * is collected by the rider on delivery, never via Hubtel. chargeable() is the
 * single computation behind every Hubtel charge + payment record.
 */
function chargeableAmount($order): float
{
    $svc = new HubtelPaymentService;
    $ref = new ReflectionMethod($svc, 'chargeable');
    $ref->setAccessible(true);

    return $ref->invoke($svc, $order);
}

it('charges goods only for an Order model', function () {
    // total 220 = 200 goods + 5 service + 15 delivery → charge 205.
    $order = Order::factory()->make(['total_amount' => 220, 'delivery_fee' => 15]);

    expect(chargeableAmount($order))->toBe(205.00);
});

it('charges goods only for a checkout-session stub', function () {
    $stub = (object) ['total_amount' => 220, 'delivery_fee' => 15];

    expect(chargeableAmount($stub))->toBe(205.00);
});

it('degrades safely to the full total when a stub lacks the delivery fee', function () {
    $stub = (object) ['total_amount' => 220];

    expect(chargeableAmount($stub))->toBe(220.00);
});

it('charges the full total when there is no delivery (pickup)', function () {
    $order = Order::factory()->make(['total_amount' => 90, 'delivery_fee' => 0]);

    expect(chargeableAmount($order))->toBe(90.00);
});
