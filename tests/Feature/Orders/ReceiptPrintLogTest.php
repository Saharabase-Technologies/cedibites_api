<?php

use App\Models\Order;
use App\Models\OrderReceiptPrint;

/**
 * The receipt is the document a customer brings back when there is a dispute,
 * so the questions these tests pin down are the ones actually asked at the
 * counter: how many slips exist, when was each produced, and who produced it.
 */
it('records one row per print, with the original first', function () {
    $order = Order::factory()->create([
        'receipt_print_count' => 0,
        'receipt_printed_at' => null,
    ]);

    foreach (range(1, 3) as $i) {
        $order->refresh();
        $isOriginal = $order->receipt_print_count === 0;
        $printedAt = now()->addSeconds($i);

        $order->forceFill([
            'receipt_printed_at' => $order->receipt_printed_at ?? $printedAt,
            'receipt_print_count' => $order->receipt_print_count + 1,
        ])->save();

        $order->receiptPrints()->create([
            'kind' => $isOriginal ? 'original' : 'reprint',
            'reprint_number' => $isOriginal ? null : $order->receipt_print_count - 1,
            'copies' => $isOriginal ? 2 : 1,
            'source' => $isOriginal ? 'order_manager' : 'pos_orders',
            'printed_at' => $printedAt,
        ]);
    }

    $prints = $order->fresh()->receiptPrints;

    expect($prints)->toHaveCount(3)
        ->and($prints[0]->kind)->toBe('original')
        ->and($prints[0]->reprint_number)->toBeNull()
        // An original is two slips off one press; a reprint is one.
        ->and($prints[0]->copies)->toBe(2)
        ->and($prints[1]->kind)->toBe('reprint')
        ->and($prints[1]->reprint_number)->toBe(1)
        ->and($prints[2]->reprint_number)->toBe(2);
});

it('keeps the reprint numbers on the log matching what the slip printed', function () {
    // The slip says "Reprint 1" for the first reprint, not "Reprint 2". A log
    // that numbered them differently would be useless for matching a customer's
    // piece of paper to a row, which is the only reason it exists.
    $order = Order::factory()->create(['receipt_print_count' => 1]);

    $order->forceFill(['receipt_print_count' => 2])->save();
    $order->receiptPrints()->create([
        'kind' => 'reprint',
        'reprint_number' => $order->receipt_print_count - 1,
        'copies' => 1,
        'printed_at' => now(),
    ]);

    expect($order->fresh()->receiptPrints->first()->reprint_number)->toBe(1);
});

it('orders prints oldest first however they were inserted', function () {
    // The relation sorts on printed_at rather than id, because the question is
    // always "what happened first", not "what reached the table first".
    $order = Order::factory()->create();

    $order->receiptPrints()->create([
        'kind' => 'reprint', 'reprint_number' => 2, 'copies' => 1,
        'printed_at' => now()->addMinutes(10),
    ]);
    $order->receiptPrints()->create([
        'kind' => 'original', 'copies' => 2,
        'printed_at' => now(),
    ]);

    expect($order->fresh()->receiptPrints->pluck('kind')->all())
        ->toBe(['original', 'reprint']);
});

it('lets go of the prints when the order is deleted', function () {
    $order = Order::factory()->create();
    $order->receiptPrints()->create(['kind' => 'original', 'copies' => 2, 'printed_at' => now()]);

    $id = $order->id;
    $order->forceDelete();

    expect(OrderReceiptPrint::where('order_id', $id)->count())->toBe(0);
});
