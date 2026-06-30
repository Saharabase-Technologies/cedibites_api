<?php

use App\Models\Employee;
use App\Models\Order;

/**
 * Third-party delivery fees are collected by the rider on delivery, tracked
 * separately from the goods payment. These tests pin that lifecycle.
 */
it('exposes goods amount as total minus delivery fee', function () {
    $order = Order::factory()->make([
        'subtotal' => 200, 'delivery_fee' => 15, 'service_charge' => 5, 'discount' => 0,
        'total_amount' => 220,
    ]);

    expect($order->goods_amount)->toBe(205.00); // 220 total − 15 delivery
});

it('marks a new delivery order delivery fee as pending', function () {
    $order = Order::factory()->create([
        'order_type' => 'delivery', 'delivery_fee' => 15, 'status' => 'received',
    ]);

    expect($order->delivery_fee_status)->toBe('pending');
});

it('marks a pickup order delivery fee as not applicable', function () {
    $order = Order::factory()->create([
        'order_type' => 'pickup', 'delivery_fee' => 0, 'status' => 'received',
    ]);

    expect($order->delivery_fee_status)->toBe('not_applicable');
});

it('treats an order created already delivered as collected', function () {
    $rider = Employee::factory()->create();

    $order = Order::factory()->create([
        'order_type' => 'delivery', 'delivery_fee' => 15, 'status' => 'delivered',
        'assigned_employee_id' => $rider->id,
    ]);

    expect($order->delivery_fee_status)->toBe('collected')
        ->and($order->delivery_fee_collected_at)->not->toBeNull()
        ->and($order->delivery_fee_collected_by)->toBe($rider->id);
});

it('auto-collects the delivery fee when the order is delivered', function () {
    $rider = Employee::factory()->create();
    $order = Order::factory()->create([
        'order_type' => 'delivery', 'delivery_fee' => 15, 'status' => 'received',
        'assigned_employee_id' => $rider->id, 'order_source' => 'online',
    ]);
    expect($order->delivery_fee_status)->toBe('pending');

    $order->update(['status' => 'delivered']);

    $order->refresh();
    expect($order->delivery_fee_status)->toBe('collected')
        ->and($order->delivery_fee_collected_at)->not->toBeNull()
        ->and($order->delivery_fee_collected_by)->toBe($rider->id);
});

it('does not collect a delivery fee that is not applicable', function () {
    $order = Order::factory()->create([
        'order_type' => 'pickup', 'delivery_fee' => 0, 'status' => 'received',
        'order_source' => 'online',
    ]);

    $order->update(['status' => 'completed']);

    $order->refresh();
    expect($order->delivery_fee_status)->toBe('not_applicable')
        ->and($order->delivery_fee_collected_at)->toBeNull();
});
