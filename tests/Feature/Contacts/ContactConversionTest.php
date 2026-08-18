<?php

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\AudienceRules;
use App\Services\Contacts\ContactConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Order::query()->forceDelete();
    Customer::query()->forceDelete();
    Contact::query()->forceDelete();
    User::query()->forceDelete();
});

function resolver(): AudienceResolver
{
    return app(AudienceResolver::class);
}

/** A guest order, so no random customer phone joins the audience. */
function guestOrder(string $phone, array $attributes = []): Order
{
    return Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => $phone,
        'status' => 'completed',
        ...$attributes,
    ]);
}

// ─── Conversion ──────────────────────────────────────────────────────────────

it('promotes a contact the moment their number places an order', function () {
    $contact = Contact::factory()->create(['phone' => '+233241234567']);

    $order = guestOrder('+233241234567');

    $contact->refresh();

    expect($contact->converted_at)->not->toBeNull()
        ->and($contact->converted_order_id)->toBe($order->id)
        ->and($contact->status())->toBe('acquired')
        ->and($contact->isAcquired())->toBeTrue();
});

it('matches an order whose number was typed in local format', function () {
    $contact = Contact::factory()->create(['phone' => '+233241234567']);

    guestOrder('0241234567');

    expect($contact->refresh()->converted_at)->not->toBeNull();
});

it('stamps the conversion with the order date, not with today', function () {
    $contact = Contact::factory()->create(['phone' => '+233241234567']);

    guestOrder('+233241234567', ['created_at' => now()->subMonths(6)]);

    expect($contact->refresh()->converted_at->isSameDay(now()->subMonths(6)))->toBeTrue();
});

it('does not re-stamp a contact who has already converted', function () {
    $contact = Contact::factory()->acquired()->create(['phone' => '+233241234567']);
    $firstConversion = $contact->converted_at;

    guestOrder('+233241234567');

    expect($contact->refresh()->converted_at->eq($firstConversion))->toBeTrue();
});

it('leaves contacts alone when an unrelated number orders', function () {
    $contact = Contact::factory()->create(['phone' => '+233241234567']);

    guestOrder('+233559999999');

    expect($contact->refresh()->converted_at)->toBeNull();
});

it('converts on a manually recorded past order too', function () {
    // Past orders skip notifications and broadcasts, but they are still
    // evidence the person bought from us.
    $contact = Contact::factory()->create(['phone' => '+233241234567']);

    guestOrder('+233241234567', ['order_source' => 'manual_entry']);

    expect($contact->refresh()->converted_at)->not->toBeNull();
});

it('catches up contacts whose orders never passed through the observer', function () {
    // An order written straight to the table — a seeder, a backfill, a migration.
    $contact = Contact::factory()->create(['phone' => '+233241234567']);
    Order::withoutEvents(fn () => guestOrder('+233241234567'));

    expect($contact->refresh()->converted_at)->toBeNull();

    $converted = app(ContactConverter::class)->reconcile();

    expect($converted)->toBe(1)
        ->and($contact->refresh()->converted_at)->not->toBeNull();
});

it('reconciles idempotently', function () {
    Contact::factory()->create(['phone' => '+233241234567']);
    Order::withoutEvents(fn () => guestOrder('+233241234567'));

    expect(app(ContactConverter::class)->reconcile())->toBe(1)
        ->and(app(ContactConverter::class)->reconcile())->toBe(0);
});

it('never creates a customer record when converting', function () {
    Contact::factory()->create(['phone' => '+233241234567']);

    $before = Customer::count();
    guestOrder('+233241234567');

    expect(Customer::count())->toBe($before);
});

// ─── Campaign targeting ──────────────────────────────────────────────────────

it('leaves imported contacts out of an audience by default', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233242222222');

    $phones = resolver()->phonesForRules(AudienceRules::fromArray(['min_orders' => 1]));

    expect($phones)->toContain('+233242222222')
        ->and($phones)->not->toContain('+233241111111');
});

it('leaves imported contacts out of the presets, including Everyone', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233242222222');

    $phones = resolver()->phones(\App\Enums\CampaignSegment::All);

    expect($phones)->toContain('+233242222222')
        ->and($phones)->not->toContain('+233241111111');
});

it('reaches supplementary contacts only when the audience asks for them', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233242222222');

    $phones = resolver()->phonesForRules(AudienceRules::fromArray([
        'sources' => ['customers', 'supplementary'],
    ]));

    expect($phones)->toContain('+233241111111')
        ->and($phones)->toContain('+233242222222');
});

it('can target the supplementary contacts alone', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233242222222');

    $phones = resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['supplementary']]));

    expect($phones)->toBe(['+233241111111']);
});

// ─── The partition ───────────────────────────────────────────────────────────

it('moves a contact out of supplementary and into customers the moment they order', function () {
    Contact::factory()->create(['phone' => '+233241111111']);

    $supplementary = fn () => resolver()->phonesForRules(
        AudienceRules::fromArray(['sources' => ['supplementary']]),
    );
    $customers = fn () => resolver()->phonesForRules(
        AudienceRules::fromArray(['sources' => ['customers']]),
    );

    expect($supplementary())->toBe(['+233241111111'])
        ->and($customers())->not->toContain('+233241111111');

    guestOrder('+233241111111');

    expect($supplementary())->toBe([])
        ->and($customers())->toContain('+233241111111');
});

it('never puts the same person in both pools', function () {
    // Converted contacts belong to Customers and only to Customers, whatever
    // list we originally found them on — so the two counts add up.
    Contact::factory()->create(['phone' => '+233241111111']);
    Contact::factory()->create(['phone' => '+233243333333']);
    guestOrder('+233241111111');
    guestOrder('+233242222222');

    $customers = resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['customers']]));
    $supplementary = resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['supplementary']]));
    $both = resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['customers', 'supplementary']]));

    expect(array_intersect($customers, $supplementary))->toBe([])
        ->and(count($customers) + count($supplementary))->toBe(count($both));
});

it('files a contact under customers even when the conversion bookkeeping is behind', function () {
    // An order written without events, so converted_at is still null while they
    // plainly have orders. Reading the order scan rather than the flag keeps the
    // partition honest — and keeps us from texting a regular as a prospect.
    Contact::factory()->create(['phone' => '+233241111111']);
    Order::withoutEvents(fn () => guestOrder('+233241111111'));

    expect(resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['supplementary']])))
        ->toBe([]);

    expect(resolver()->phonesForRules(AudienceRules::fromArray(['sources' => ['customers']])))
        ->toContain('+233241111111');
});

it('counts a converted contact once, not twice', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233241111111');

    $phones = resolver()->phonesForRules(AudienceRules::fromArray([
        'sources' => ['customers', 'supplementary'],
    ]));

    expect(array_count_values($phones)['+233241111111'])->toBe(1);
});

it('drops supplementary contacts from any rule about behaviour they do not have', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233242222222', ['created_at' => now()->subDay()]);

    $phones = resolver()->phonesForRules(AudienceRules::fromArray([
        'sources' => ['customers', 'supplementary'],
        'ordered_within_days' => 30,
    ]));

    expect($phones)->toBe(['+233242222222']);
});

it('treats never having ordered as having not ordered for a long time', function () {
    Contact::factory()->create(['phone' => '+233241111111']);

    $phones = resolver()->phonesForRules(AudienceRules::fromArray([
        'sources' => ['supplementary'],
        'not_ordered_for_days' => 60,
    ]));

    expect($phones)->toBe(['+233241111111']);
});

it('filters supplementary contacts by network, which is readable from the prefix', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    Contact::factory()->create(['phone' => '+233201111111']);

    $mtn = resolver()->phonesForRules(AudienceRules::fromArray([
        'sources' => ['supplementary'],
        'networks' => ['mtn'],
    ]));

    expect($mtn)->toContain('+233241111111')
        ->and($mtn)->not->toContain('+233201111111');
});

it('resolves an empty custom audience to everybody, including customers who never ordered', function () {
    /*
     * The shape that produced "0 people" on beta. Four registered customers
     * with no orders: opening the audience builder used to inject "ordered in
     * the last 30 days" to keep its own tab open, which excluded every one of
     * them, and the operator only found out at the send screen.
     *
     * An empty builder has to mean everybody, and everybody has to include
     * account holders who have not got round to ordering yet.
     */
    foreach (['+233241111111', '+233242222222', '+233243333333', '+233244444444'] as $phone) {
        $user = User::factory()->create(['phone' => $phone]);
        Customer::create(['user_id' => $user->id, 'is_guest' => false, 'status' => 'active']);
    }

    expect(resolver()->countRules(AudienceRules::fromArray([])))->toBe(4)
        ->and(resolver()->countRules(AudienceRules::fromArray(['sources' => ['customers']])))->toBe(4)
        ->and(resolver()->countRules(AudienceRules::fromArray(['sources' => ['customers', 'supplementary']])))->toBe(4)
        // And the condition that used to be injected really does empty it, which
        // is why it must never be added on the operator's behalf.
        ->and(resolver()->countRules(AudienceRules::fromArray(['ordered_within_days' => 30])))->toBe(0);
});

it('keeps a plain rule set empty, so presets still resolve as presets', function () {
    // sources defaults to customers-only and must not be written into the rules,
    // or CampaignSender would stop treating a preset campaign as a preset.
    expect(AudienceRules::fromArray([])->isEmpty())->toBeTrue()
        ->and(AudienceRules::fromArray(['sources' => ['customers']])->isEmpty())->toBeTrue()
        ->and(AudienceRules::fromArray(['sources' => ['supplementary']])->isEmpty())->toBeFalse();
});

it('says in plain English when an audience reaches beyond our customers', function () {
    $description = AudienceRules::fromArray(['sources' => ['customers', 'supplementary']])->describe();

    expect($description[0])->toContain('Supplementary');
});

// ─── Watching conversions ────────────────────────────────────────────────────

it('records every conversion in the activity log', function () {
    $contact = Contact::factory()->create(['phone' => '+233241111111', 'created_at' => now()->subDays(9)]);

    guestOrder('+233241111111');

    $entry = ActivityLog::where('log_name', 'contacts')->where('event', 'contact_converted')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->subject_id)->toBe($contact->id)
        ->and($entry->properties['phone'])->toBe('+233241111111')
        ->and($entry->properties['days_to_convert'])->toBe(9)
        ->and($entry->properties['via'])->toBe('order');
});

it('records conversions found by the reconcile command too', function () {
    Contact::factory()->create(['phone' => '+233241111111']);
    Order::withoutEvents(fn () => guestOrder('+233241111111'));

    app(ContactConverter::class)->reconcile();

    expect(ActivityLog::where('event', 'contact_converted')->first()->properties['via'])
        ->toBe('reconcile');
});

it('keeps the conversion record after the contact is deleted', function () {
    // The point of logging it rather than reading converted_at: an undone import
    // or a removed contact must not erase what the list achieved.
    $contact = Contact::factory()->create(['phone' => '+233241111111']);
    guestOrder('+233241111111');

    $contact->forceDelete();

    expect(ActivityLog::where('event', 'contact_converted')->count())->toBe(1);
});

it('logs one conversion per contact, however many times they order', function () {
    Contact::factory()->create(['phone' => '+233241111111']);

    guestOrder('+233241111111');
    guestOrder('+233241111111');
    guestOrder('+233241111111');

    expect(ActivityLog::where('event', 'contact_converted')->count())->toBe(1);
});

it('measures days to convert, and refuses to for somebody who was already a customer', function () {
    $acquired = Contact::factory()->create(['created_at' => now()->subDays(12)]);
    $acquired->update(['converted_at' => now()]);

    $found = Contact::factory()->alreadyCustomer()->create(['created_at' => now()->subDays(12)]);

    expect($acquired->fresh()->daysToConvert())->toBe(12)
        // Converted a year before we imported them. A number here would read as
        // the list converting people before it existed.
        ->and($found->daysToConvert())->toBeNull();
});
