<?php

use App\Enums\GhanaNetwork;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\AudienceRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    User::query()->forceDelete();

    $this->resolver = app(AudienceResolver::class);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * A guest order. Guests on purpose: a registered customer would drag a second,
 * random phone into the audience through their user record and every count in
 * this file would drift with the faker seed.
 */
function order(string $phone, array $attributes = []): Order
{
    return Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => $phone,
        'contact_name' => 'Ama',
        'status' => 'completed',
        ...$attributes,
    ]);
}

function phonesFor(array $rules): array
{
    $found = test()->resolver->phonesForRules(AudienceRules::fromArray($rules));
    sort($found);

    return $found;
}

// ─── Recency ─────────────────────────────────────────────────────────────────

describe('when they ordered', function () {
    it('finds people who ordered inside a window', function () {
        order('+233241111111', ['created_at' => now()->subDays(2)]);
        order('+233242222222', ['created_at' => now()->subDays(40)]);

        expect(phonesFor(['ordered_within_days' => 7]))->toBe(['+233241111111']);
    });

    it('finds people who have gone quiet', function () {
        order('+233241111111', ['created_at' => now()->subDays(2)]);
        order('+233242222222', ['created_at' => now()->subDays(90)]);

        expect(phonesFor(['not_ordered_for_days' => 60]))->toBe(['+233242222222']);
    });

    it('finds people who ordered between two dates', function () {
        order('+233241111111', ['created_at' => now()->subDays(10)]);
        order('+233242222222', ['created_at' => now()->subDays(100)]);

        $found = phonesFor([
            'ordered_after' => now()->subDays(20)->toDateString(),
            'ordered_before' => now()->toDateString(),
        ]);

        expect($found)->toBe(['+233241111111']);
    });

    /* Recency is measured from the most recent order, not the first. */
    it('measures from the latest order a number has', function () {
        order('+233241111111', ['created_at' => now()->subDays(300)]);
        order('+233241111111', ['created_at' => now()->subDay()]);

        expect(phonesFor(['ordered_within_days' => 7]))->toBe(['+233241111111'])
            ->and(phonesFor(['not_ordered_for_days' => 60]))->toBe([]);
    });
});

// ─── Behaviour ───────────────────────────────────────────────────────────────

describe('how much and how often', function () {
    it('filters on the number of orders', function () {
        order('+233241111111');
        order('+233242222222');
        order('+233242222222');
        order('+233242222222');

        expect(phonesFor(['min_orders' => 2]))->toBe(['+233242222222'])
            ->and(phonesFor(['max_orders' => 1]))->toBe(['+233241111111']);
    });

    it('filters on total spend across every order', function () {
        order('+233241111111', ['total_amount' => 30]);
        order('+233242222222', ['total_amount' => 60]);
        order('+233242222222', ['total_amount' => 60]);

        expect(phonesFor(['min_spend' => 100]))->toBe(['+233242222222'])
            ->and(phonesFor(['max_spend' => 50]))->toBe(['+233241111111']);
    });

    it('filters on the branch they ordered from', function () {
        $east = Branch::factory()->create(['name' => 'East Legon']);
        $spintex = Branch::factory()->create(['name' => 'Spintex']);

        order('+233241111111', ['branch_id' => $east->id]);
        order('+233242222222', ['branch_id' => $spintex->id]);

        expect(phonesFor(['branch_ids' => [$east->id]]))->toBe(['+233241111111']);
    });

    it('filters on the hour they ordered', function () {
        order('+233241111111', ['created_at' => now()->setTime(12, 30)]);   // lunch
        order('+233242222222', ['created_at' => now()->setTime(20, 15)]);   // dinner

        expect(phonesFor(['hour_from' => 11, 'hour_to' => 15]))->toBe(['+233241111111'])
            ->and(phonesFor(['hour_from' => 18, 'hour_to' => 23]))->toBe(['+233242222222']);
    });

    it('filters on what they bought', function () {
        $jollof = MenuItem::factory()->create(['name' => 'Assorted Jollof']);
        $waakye = MenuItem::factory()->create(['name' => 'Waakye']);

        $a = order('+233241111111');
        $b = order('+233242222222');

        OrderItem::factory()->create(['order_id' => $a->id, 'menu_item_id' => $jollof->id]);
        OrderItem::factory()->create(['order_id' => $b->id, 'menu_item_id' => $waakye->id]);

        expect(phonesFor(['menu_item_ids' => [$jollof->id]]))->toBe(['+233241111111']);
    });
});

// ─── Network ─────────────────────────────────────────────────────────────────

describe('network, read off the prefix', function () {
    it('identifies each network from the number', function () {
        expect(GhanaNetwork::forPhone('+233241234567'))->toBe(GhanaNetwork::Mtn)
            ->and(GhanaNetwork::forPhone('+233201234567'))->toBe(GhanaNetwork::Telecel)
            ->and(GhanaNetwork::forPhone('+233271234567'))->toBe(GhanaNetwork::AirtelTigo)
            ->and(GhanaNetwork::forPhone('+233231234567'))->toBe(GhanaNetwork::Glo);
    });

    /* The same number arrives in three shapes across users and order contacts. */
    it('reads the prefix whichever form the number is in', function () {
        expect(GhanaNetwork::forPhone('0241234567'))->toBe(GhanaNetwork::Mtn)
            ->and(GhanaNetwork::forPhone('233241234567'))->toBe(GhanaNetwork::Mtn)
            ->and(GhanaNetwork::forPhone('241234567'))->toBe(GhanaNetwork::Mtn);
    });

    it('does not guess at an unrecognised prefix', function () {
        expect(GhanaNetwork::forPhone('+233991234567'))->toBeNull()
            ->and(GhanaNetwork::forPhone('nonsense'))->toBeNull();
    });

    it('targets a network', function () {
        order('+233241111111');  // MTN
        order('+233201111111');  // Telecel
        order('+233271111111');  // AirtelTigo

        expect(phonesFor(['networks' => ['mtn']]))->toBe(['+233241111111'])
            ->and(phonesFor(['networks' => ['telecel', 'airteltigo']]))
            ->toBe(['+233201111111', '+233271111111']);
    });
});

// ─── How rules combine ───────────────────────────────────────────────────────

describe('stacking rules', function () {
    /*
     * Rules combine with AND, so adding one can only ever shrink the audience.
     * That property is what makes the builder safe: there is no way to widen a
     * send by editing a filter.
     */
    it('narrows with every rule added', function () {
        $jollof = MenuItem::factory()->create();

        $mtnJollof = order('+233241111111', ['created_at' => now()->subDay()]);
        $mtnWaakye = order('+233242222222', ['created_at' => now()->subDay()]);
        order('+233201111111', ['created_at' => now()->subDay()]);  // Telecel

        OrderItem::factory()->create(['order_id' => $mtnJollof->id, 'menu_item_id' => $jollof->id]);
        OrderItem::factory()->create(['order_id' => $mtnWaakye->id, 'menu_item_id' => MenuItem::factory()->create()->id]);

        expect(phonesFor(['ordered_within_days' => 7]))->toHaveCount(3)
            ->and(phonesFor(['ordered_within_days' => 7, 'networks' => ['mtn']]))->toHaveCount(2)
            ->and(phonesFor([
                'ordered_within_days' => 7,
                'networks' => ['mtn'],
                'menu_item_ids' => [$jollof->id],
            ]))->toBe(['+233241111111']);
    });

    /* Within one rule the options are alternatives; between rules everything holds. */
    it('treats several branches as any-of, not all-of', function () {
        $east = Branch::factory()->create();
        $spintex = Branch::factory()->create();

        order('+233241111111', ['branch_id' => $east->id]);
        order('+233242222222', ['branch_id' => $spintex->id]);

        expect(phonesFor(['branch_ids' => [$east->id, $spintex->id]]))->toHaveCount(2);
    });

    it('matches everybody when nothing is set', function () {
        order('+233241111111');
        order('+233242222222');

        expect(phonesFor([]))->toHaveCount(2);
    });

    /* Cancelled orders are not evidence of anything, in any rule. */
    it('ignores cancelled orders', function () {
        order('+233241111111', ['status' => 'cancelled', 'created_at' => now()->subDay()]);

        expect(phonesFor(['ordered_within_days' => 7]))->toBe([]);
    });
});

// ─── Reading it back ─────────────────────────────────────────────────────────

it('describes the rules in plain English', function () {
    $rules = AudienceRules::fromArray([
        'ordered_within_days' => 30,
        'networks' => ['mtn'],
        'min_orders' => 2,
    ]);

    expect($rules->describe())->toBe([
        'Ordered in the last 30 days',
        'On MTN',
        '2 orders or more',
    ]);
});

it('says so when the rule set is empty', function () {
    expect(AudienceRules::fromArray([])->describe())->toBe(['Everybody we hold a number for'])
        ->and(AudienceRules::fromArray([])->isEmpty())->toBeTrue();
});

/* Only what was set, so a stored rule set reads back as what was chosen. */
it('stores only the rules that were set', function () {
    $rules = AudienceRules::fromArray(['ordered_within_days' => 7, 'min_orders' => null]);

    expect($rules->toArray())->toBe(['ordered_within_days' => 7]);
});
