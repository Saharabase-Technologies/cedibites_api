<?php

use App\Models\Order;
use App\Services\OrderNumberService;

function generateOrderNumber(): string
{
    return (new OrderNumberService)->generate();
}

it('starts the series at A001 on an empty table', function () {
    expect(generateOrderNumber())->toBe('A001');
});

it('continues from the highest number in the series', function () {
    Order::factory()->create(['order_number' => 'AH636']);

    expect(generateOrderNumber())->toBe('AH637');
});

it('rolls the prefix over at 999', function () {
    Order::factory()->create(['order_number' => 'AH999']);

    expect(generateOrderNumber())->toBe('AI001');
});

it('skips a number held by a soft-deleted order', function () {
    Order::factory()->create(['order_number' => 'AH636']);
    Order::factory()->create(['order_number' => 'AH637'])->delete();

    // The unique index still counts AH637, so handing it out again is an insert
    // that fails. This is what stopped every order on prod on 2026-09-04.
    expect(generateOrderNumber())->toBe('AH638');
});

it('leaves a soft-deleted order holding its number', function () {
    $order = Order::factory()->create(['order_number' => 'AH637']);
    $order->delete();

    Order::create([
        ...Order::factory()->raw(),
        'order_number' => generateOrderNumber(),
    ]);

    expect(Order::withTrashed()->pluck('order_number')->sort()->values()->all())
        ->toBe(['AH637', 'AH638']);
});
