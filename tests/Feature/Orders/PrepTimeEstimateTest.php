<?php

use App\Domain\Orders\PrepTimeEstimator;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Notifications\OrderConfirmedNotification;

/*
|--------------------------------------------------------------------------
| What the customer is told their food will take
|--------------------------------------------------------------------------
|
| `orders.estimated_prep_time` was a nullable column nothing ever wrote, and
| the confirmation SMS interpolated it unguarded — so every confirmation ever
| sent read "Estimated time:  mins." with a hole in it. Two halves to the fix,
| and both are pinned here: the number is now measured and stamped at creation,
| and the sentence can no longer render half-empty if it ever goes missing
| again.
|
| The cap is the part worth understanding. The quote is a promise the business
| is making, not a report on how the kitchen has been doing — so a branch
| having a bad week is still not allowed to tell customers to expect 40
| minutes.
*/

/** Post a preparing → ready pair for an order, `$minutes` apart. */
function recordPrepRun(Order $order, float $minutes): void
{
    $start = now()->subDay();

    OrderStatusHistory::create([
        'order_id' => $order->id,
        'status' => 'preparing',
        'changed_at' => $start,
    ]);

    OrderStatusHistory::create([
        'order_id' => $order->id,
        'status' => 'ready',
        'changed_at' => $start->copy()->addMinutes($minutes),
    ]);
}

/** @param  array<int, float>  $durations */
function branchWithPrepHistory(array $durations): Branch
{
    $branch = Branch::factory()->create();

    foreach ($durations as $minutes) {
        recordPrepRun(Order::factory()->create(['branch_id' => $branch->id]), $minutes);
    }

    return $branch;
}

it('quotes the configured default to a branch with no history at all', function () {
    config()->set('orders.prep_time.default_minutes', 15);

    $branch = Branch::factory()->create();

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(15);
});

it('quotes the default rather than trusting a thin sample', function () {
    // A single very fast order must not set the promise for every order after
    // it — which is exactly the position a newly opened branch is in.
    config()->set('orders.prep_time.default_minutes', 15);
    config()->set('orders.prep_time.min_sample', 5);

    $branch = branchWithPrepHistory([6, 7]);

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(15);
});

it("uses the branch's own median once there is enough history", function () {
    config()->set('orders.prep_time.min_sample', 5);
    config()->set('orders.prep_time.max_minutes', 15);

    $branch = branchWithPrepHistory([8, 9, 10, 11, 12]);

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(10);
});

it('never quotes longer than the cap, however slow the kitchen has been', function () {
    config()->set('orders.prep_time.min_sample', 5);
    config()->set('orders.prep_time.max_minutes', 15);

    $branch = branchWithPrepHistory([40, 45, 50, 55, 60]);

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(15);
});

it('never quotes shorter than the floor', function () {
    config()->set('orders.prep_time.min_sample', 3);
    config()->set('orders.prep_time.min_minutes', 5);

    $branch = branchWithPrepHistory([1, 1, 1]);

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(5);
});

it('ignores an order left in preparing overnight', function () {
    // One forgotten "ready" press would drag a mean up on its own. The sanity
    // filter drops it, and the median would have resisted it anyway.
    config()->set('orders.prep_time.min_sample', 3);
    config()->set('orders.prep_time.max_minutes', 60);

    $branch = branchWithPrepHistory([10, 10, 10]);
    recordPrepRun(Order::factory()->create(['branch_id' => $branch->id]), 600);

    expect(app(PrepTimeEstimator::class)->forBranch($branch->id))->toBe(10);
});

it('measures each branch separately', function () {
    config()->set('orders.prep_time.min_sample', 3);
    config()->set('orders.prep_time.max_minutes', 60);

    $quick = branchWithPrepHistory([6, 6, 6]);
    $slow = branchWithPrepHistory([30, 30, 30]);

    $estimator = app(PrepTimeEstimator::class);

    expect($estimator->forBranch($quick->id))->toBe(6)
        ->and($estimator->forBranch($slow->id))->toBe(30);
});

it('stamps the estimate on every order at creation', function () {
    // The hook is on the model, not the three controllers that create orders,
    // so a path nobody remembered to update still gets it.
    config()->set('orders.prep_time.default_minutes', 12);
    config()->set('orders.prep_time.min_minutes', 5);
    config()->set('orders.prep_time.max_minutes', 15);

    $order = Order::factory()->create(['estimated_prep_time' => null]);

    expect($order->fresh()->estimated_prep_time)->toBe(12);
});

it('leaves an estimate the caller supplied alone', function () {
    $order = Order::factory()->create(['estimated_prep_time' => 25]);

    expect($order->fresh()->estimated_prep_time)->toBe(25);
});

it('omits the estimate sentence rather than rendering it empty', function () {
    $order = Order::factory()->create([
        'order_number' => 'AG013',
        'total_amount' => 0.10,
        'estimated_prep_time' => null,
    ]);

    // Written straight to the column: the creating hook fills a null, and the
    // point here is what the message does if one ever survives anyway.
    $order->updateQuietly(['estimated_prep_time' => null]);

    $sms = (new OrderConfirmedNotification($order->fresh()))->toSms($order->customer);

    expect($sms)->toBe('CediBites: Order #AG013 confirmed! Total: GHS 0.10.')
        ->and($sms)->not->toContain('mins');
});

it('includes the estimate when there is one', function () {
    $order = Order::factory()->create([
        'order_number' => 'AG014',
        'total_amount' => 45.5,
        'estimated_prep_time' => 15,
    ]);

    expect((new OrderConfirmedNotification($order))->toSms($order->customer))
        ->toBe('CediBites: Order #AG014 confirmed! Total: GHS 45.50. Estimated time: 15 mins.');
});

it('formats a large total with a thousands separator', function () {
    $order = Order::factory()->create([
        'order_number' => 'AG015',
        'total_amount' => 1234.5,
        'estimated_prep_time' => 15,
    ]);

    expect((new OrderConfirmedNotification($order))->toSms($order->customer))
        ->toContain('GHS 1,234.50');
});
