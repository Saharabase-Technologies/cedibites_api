<?php

use App\Domain\Inventory\Movements\Engines\WeightedAverageCostCalculator;

beforeEach(function () {
    $this->calc = new WeightedAverageCostCalculator();
});

it('uses the incoming cost when starting from zero stock', function () {
    expect($this->calc->next(0, 0, 10, 5.0))->toBe(5.0);
});

it('blends old and incoming cost on receipt', function () {
    // 25 @ 4.70 + 15 @ 5.00 = 192.5 / 40 = 4.8125
    expect($this->calc->next(25, 4.70, 15, 5.00))->toBe(4.8125);
});

it('leaves cost unchanged for issues (negative qty)', function () {
    expect($this->calc->next(40, 4.8125, -10, null))->toBe(4.8125);
});

it('leaves cost unchanged when no incoming unit cost is given', function () {
    expect($this->calc->next(10, 5.0, 5, null))->toBe(5.0);
});
