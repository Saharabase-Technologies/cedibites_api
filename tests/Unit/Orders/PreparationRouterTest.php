<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Services\Orders\PreparationRouter;

/**
 * The opening status of a new order.
 *
 * Two independent questions decide it, and these tests are arranged that way:
 * whether anything on the ticket needs cooking, and where the order was taken.
 */
function router(): PreparationRouter
{
    return app(PreparationRouter::class);
}

/** An item that answers for itself, so no category lookup is involved. */
function item(bool $needsCooking): MenuItem
{
    return MenuItem::factory()->make(['requires_preparation' => $needsCooking]);
}

describe('an order with something to cook', function () {
    it('opens already accepted when it was rung up at the till', function () {
        expect(router()->initialStatus(collect([item(true)]), 'pos'))->toBe('accepted');
    });

    it('opens in new when it came from online, where somebody must accept it', function () {
        expect(router()->initialStatus(collect([item(true)]), 'online'))->toBe('received');
    });

    it('opens in new when it came over WhatsApp', function () {
        expect(router()->initialStatus(collect([item(true)]), 'whatsapp'))->toBe('received');
    });

    it('keeps the whole ticket on the board when only one line needs a pan', function () {
        $mixed = collect([item(false), item(true)]);

        expect(router()->initialStatus($mixed, 'pos'))->toBe('accepted')
            ->and(router()->initialStatus($mixed, 'online'))->toBe('received');
    });

    it('is still kitchen work, whichever status it opened in', function () {
        expect(router()->skipsKitchen(collect([item(true)]), 'pos'))->toBeFalse()
            ->and(router()->skipsKitchen(collect([item(true)]), 'online'))->toBeFalse();
    });
});

describe('an order with nothing to cook', function () {
    it('is complete at the till, because the handover was the transaction', function () {
        expect(router()->initialStatus(collect([item(false)]), 'pos'))->toBe('completed');
    });

    it('waits in ready when ordered online, because somebody still hands it over', function () {
        expect(router()->initialStatus(collect([item(false)]), 'online'))->toBe('ready');
    });

    it('skips the kitchen from either source', function () {
        expect(router()->skipsKitchen(collect([item(false)]), 'pos'))->toBeTrue()
            ->and(router()->skipsKitchen(collect([item(false)]), 'online'))->toBeTrue();
    });
});

describe('an item that defers to its category', function () {
    it('opens a till sale in accepted when the category needs preparation', function () {
        $category = MenuCategory::factory()->create(['requires_preparation' => true]);
        $menuItem = MenuItem::factory()->make([
            'requires_preparation' => null,
            'category_id' => $category->id,
        ]);

        expect(router()->initialStatus(collect([$menuItem]), 'pos'))->toBe('accepted');
    });

    it('completes a till sale when the category needs none', function () {
        $category = MenuCategory::factory()->create(['requires_preparation' => false]);
        $menuItem = MenuItem::factory()->make([
            'requires_preparation' => null,
            'category_id' => $category->id,
        ]);

        expect(router()->initialStatus(collect([$menuItem]), 'pos'))->toBe('completed');
    });
});

describe('an order whose contents are unknown', function () {
    it('is treated as normal work, opened by where it was taken', function () {
        expect(router()->initialStatus(collect(), 'pos'))->toBe('accepted')
            ->and(router()->initialStatus(collect(), 'online'))->toBe('received');
    });

    it('is never assumed to skip the kitchen', function () {
        expect(router()->skipsKitchen(collect(), 'pos'))->toBeFalse()
            ->and(router()->skipsKitchen(collect(), 'online'))->toBeFalse();
    });
});
