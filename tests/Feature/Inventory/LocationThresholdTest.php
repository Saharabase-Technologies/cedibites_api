<?php

use App\Models\Inventory\Item;
use App\Models\Inventory\ItemLocationThreshold;
use App\Models\Inventory\Location;
use Database\Seeders\InventoryThresholdSeeder;

/**
 * A branch holding a day of cover was judged against a central warehouse's
 * reorder point, so it read Critical on nearly every line whatever it held.
 * These pin the resolution: a location's own figure where it has one, the
 * item's global figure otherwise, each half falling back independently.
 */
it('falls back to the item global when the location sets nothing', function () {
    $item = Item::factory()->create(['reorder_level' => 100, 'min_threshold' => 40]);
    $location = Location::factory()->satellite()->create();

    expect($item->thresholdsAt($location->id))->toMatchArray([
        'reorder_level' => 100.0,
        'min_threshold' => 40.0,
        'is_location_specific' => false,
    ]);
});

it('prefers the location figure over the item global', function () {
    $item = Item::factory()->create(['reorder_level' => 1000, 'min_threshold' => 400]);
    $location = Location::factory()->satellite()->create();

    ItemLocationThreshold::create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'reorder_level' => 150,
        'min_threshold' => 60,
    ]);

    expect($item->thresholdsAt($location->id))->toMatchArray([
        'reorder_level' => 150.0,
        'min_threshold' => 60.0,
        'is_location_specific' => true,
    ]);
});

it('lets each half fall back on its own', function () {
    $item = Item::factory()->create(['reorder_level' => 1000, 'min_threshold' => 400]);
    $location = Location::factory()->satellite()->create();

    // Overrides the reorder point only; inherits the critical minimum.
    ItemLocationThreshold::create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'reorder_level' => 150,
        'min_threshold' => null,
    ]);

    expect($item->thresholdsAt($location->id))->toMatchArray([
        'reorder_level' => 150.0,
        'min_threshold' => 400.0,
    ]);
});

it('keeps the global figure for a company-wide view', function () {
    $item = Item::factory()->create(['reorder_level' => 1000, 'min_threshold' => 400]);
    $location = Location::factory()->satellite()->create();

    ItemLocationThreshold::create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'reorder_level' => 150,
        'min_threshold' => 60,
    ]);

    // No location in scope: one branch's reorder point is not the company's.
    expect($item->thresholdsAt(null))->toMatchArray([
        'reorder_level' => 1000.0,
        'min_threshold' => 400.0,
        'is_location_specific' => false,
    ]);
});

it('repairs a threshold pair that was shipped inverted', function () {
    // min above reorder: the band check tests min first, so Low is unreachable
    // and stock jumps from OK straight to Critical.
    $item = Item::factory()->create([
        'name' => 'Chicken Thighs (Bone-in)',
        'reorder_level' => 75,
        'min_threshold' => 150,
    ]);

    (new InventoryThresholdSeeder)->run();

    $item->refresh();

    expect((float) $item->min_threshold)->toBeLessThan((float) $item->reorder_level);
});

it('gives thresholds to an item shipped with none', function () {
    $item = Item::factory()->create([
        'name' => 'Parboiled Rice',
        'reorder_level' => null,
        'min_threshold' => null,
    ]);

    (new InventoryThresholdSeeder)->run();

    $item->refresh();

    expect($item->reorder_level)->not->toBeNull()
        ->and($item->min_threshold)->not->toBeNull();
});

it('leaves a sane threshold pair somebody chose alone', function () {
    $item = Item::factory()->create([
        'name' => 'Eggs',
        'reorder_level' => 12,
        'min_threshold' => 4,
    ]);

    (new InventoryThresholdSeeder)->run();

    $item->refresh();

    expect((float) $item->reorder_level)->toBe(12.0)
        ->and((float) $item->min_threshold)->toBe(4.0);
});
