<?php

use App\Models\Order;
use App\Models\Payment;
use App\Services\Analytics\AnalyticsQueryBuilder;
use App\Services\Analytics\AnalyticsService;

/**
 * Third-party delivery fees are a pass-through to independent riders and must
 * never be counted as restaurant revenue. These tests pin that contract on the
 * canonical analytics layer so the figures self-correct for historical orders.
 */
beforeEach(function () {
    $this->qb = app(AnalyticsQueryBuilder::class);
    $this->service = app(AnalyticsService::class);
});

/** Create a completed, revenue-contributing order with explicit money fields. */
function revenueOrder(float $subtotal, float $deliveryFee, float $serviceCharge = 0.0): Order
{
    $order = Order::factory()->create([
        'order_type' => $deliveryFee > 0 ? 'delivery' : 'pickup',
        'status' => 'completed',
        'subtotal' => $subtotal,
        'delivery_fee' => $deliveryFee,
        'service_charge' => $serviceCharge,
        'discount' => 0,
        'total_amount' => $subtotal - 0 + $serviceCharge + $deliveryFee,
    ]);

    Payment::factory()->completed()->create([
        'order_id' => $order->id,
        'amount' => $order->total_amount,
    ]);

    return $order;
}

it('excludes the delivery fee from canonical revenue', function () {
    // ₵200 goods + ₵15 delivery → revenue is ₵200, not ₵215.
    revenueOrder(200.00, 15.00);

    expect($this->qb->computeRevenue())->toBe(200.00)
        ->and($this->qb->computeDeliveryFees())->toBe(15.00);
});

it('tracks delivery fees separately across many orders', function () {
    revenueOrder(200.00, 15.00);
    revenueOrder(100.00, 10.00);
    revenueOrder(50.00, 0.00); // pickup: no fee

    // Goods revenue = 200 + 100 + 50 = 350. Delivery fees = 15 + 10 = 25.
    expect($this->qb->computeRevenue())->toBe(350.00)
        ->and($this->qb->computeDeliveryFees())->toBe(25.00);
});

it('keeps service charge inside revenue but excludes delivery', function () {
    // total_amount = 200 (goods) + 5 (service) + 15 (delivery) = 220.
    // Revenue should be 205 (goods + service), not 220.
    revenueOrder(200.00, 15.00, 5.00);

    expect($this->qb->computeRevenue())->toBe(205.00);
});

it('reports delivery fees as a distinct figure in sales metrics', function () {
    revenueOrder(200.00, 15.00);

    $metrics = $this->service->getSalesMetrics();

    expect($metrics['total_sales'])->toBe(200.00)
        ->and($metrics['delivery_fees'])->toBe(15.00);
});

it('reports delivery fees today in dashboard metrics', function () {
    revenueOrder(200.00, 15.00);

    $kpis = $this->service->getDashboardMetrics();

    expect($kpis['revenue_today'])->toBe(200.00)
        ->and($kpis['delivery_fees_today'])->toBe(15.00);
});

it('excludes delivery fee from delivery-type goods revenue', function () {
    revenueOrder(200.00, 15.00);

    $metrics = $this->service->getDeliveryPickupMetrics();

    // Goods revenue from delivery orders excludes the fee; the fee is its own line.
    expect($metrics['delivery_revenue'])->toBe(200.00)
        ->and($metrics['delivery_fees'])->toBe(15.00);
});
