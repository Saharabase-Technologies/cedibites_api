<?php

use App\Http\Resources\ShiftResource;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftOrder;
use Illuminate\Http\Request;

/**
 * A shift's stored total_sales is the gross figure (goods + third-party delivery).
 * The resource splits it into goods (restaurant revenue) and deliveryFees
 * (pass-through to riders) so the shift reconciles: goods + delivery = total.
 */
it('splits shift total_sales into goods and delivery', function () {
    // Two delivery orders: goods 200 + 100, delivery 15 + 10 → totals 215 + 110.
    $orderA = Order::factory()->create(['delivery_fee' => 15, 'total_amount' => 215]);
    $orderB = Order::factory()->create(['delivery_fee' => 10, 'total_amount' => 110]);

    $shift = Shift::factory()->create(['total_sales' => 325, 'order_count' => 2]);
    ShiftOrder::factory()->create(['shift_id' => $shift->id, 'order_id' => $orderA->id, 'order_total' => 215]);
    ShiftOrder::factory()->create(['shift_id' => $shift->id, 'order_id' => $orderB->id, 'order_total' => 110]);

    $data = (new ShiftResource($shift->fresh()))->toArray(Request::create('/'));

    expect($data['totalSales'])->toBe(325.0)
        ->and($data['deliveryFees'])->toBe(25.0)
        ->and($data['goodsSales'])->toBe(300.0)
        ->and($data['goodsSales'] + $data['deliveryFees'])->toBe($data['totalSales']);
});

it('reports zero delivery fees for a non-delivery shift', function () {
    $order = Order::factory()->create(['delivery_fee' => 0, 'total_amount' => 80]);

    $shift = Shift::factory()->create(['total_sales' => 80, 'order_count' => 1]);
    ShiftOrder::factory()->create(['shift_id' => $shift->id, 'order_id' => $order->id, 'order_total' => 80]);

    $data = (new ShiftResource($shift->fresh()))->toArray(Request::create('/'));

    expect($data['deliveryFees'])->toBe(0.0)
        ->and($data['goodsSales'])->toBe(80.0);
});

it('treats an empty shift as all goods', function () {
    $shift = Shift::factory()->create(['total_sales' => 0, 'order_count' => 0]);

    $data = (new ShiftResource($shift->fresh()))->toArray(Request::create('/'));

    expect($data['deliveryFees'])->toBe(0.0)
        ->and($data['goodsSales'])->toBe(0.0)
        ->and($data['totalSales'])->toBe(0.0);
});
