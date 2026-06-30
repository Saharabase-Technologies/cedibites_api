<?php

use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Analytics\AnalyticsService;

/**
 * The Transactions ledger reports GOODS only — the third-party delivery fee is
 * excluded even for historical payments whose amount was recorded as the full
 * total (forward-only cutover, no history rewrite).
 */
it('totals goods only in payment stats, excluding delivery', function () {
    // Historical-style payment: amount = full total (220 = 205 goods + 15 delivery).
    $order = Order::factory()->create([
        'subtotal' => 200, 'service_charge' => 5, 'delivery_fee' => 15, 'discount' => 0,
        'total_amount' => 220,
    ]);
    Payment::factory()->completed()->create(['order_id' => $order->id, 'amount' => 220]);

    $stats = app(AnalyticsService::class)->getPaymentStats();

    expect($stats['completed']['total'])->toBe(205.00)
        ->and($stats['completed']['count'])->toBe(1);
});

it('exposes a goods_amount on the payment resource capped at goods', function () {
    $order = Order::factory()->create(['total_amount' => 220, 'delivery_fee' => 15]);
    $payment = Payment::factory()->completed()->create(['order_id' => $order->id, 'amount' => 220]);
    $payment->load('order');

    $arr = (new PaymentResource($payment))->toArray(request());

    expect($arr['goods_amount'])->toBe(205.00);
});

it('leaves goods_amount equal to amount when there is no delivery', function () {
    $order = Order::factory()->create(['total_amount' => 90, 'delivery_fee' => 0]);
    $payment = Payment::factory()->completed()->create(['order_id' => $order->id, 'amount' => 90]);
    $payment->load('order');

    $arr = (new PaymentResource($payment))->toArray(request());

    expect($arr['goods_amount'])->toBe(90.00);
});
