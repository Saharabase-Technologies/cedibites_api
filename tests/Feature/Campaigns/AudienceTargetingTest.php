<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
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
});

function targetingResolver(): AudienceResolver
{
    return app(AudienceResolver::class);
}

function targetedPhones(array $rules): array
{
    return targetingResolver()->phonesForRules(AudienceRules::fromArray($rules));
}

/** A guest order, so no registered customer's own number joins the audience. */
function orderPlacedAt(string $phone, ?Branch $branch = null, ?MenuItemOption $option = null, array $attributes = []): Order
{
    $order = Order::factory()->create([
        'customer_id' => null,
        'contact_phone' => $phone,
        'status' => 'completed',
        'branch_id' => $branch?->id ?? Branch::factory(),
        ...$attributes,
    ]);

    if ($option) {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $option->menu_item_id,
            'menu_item_option_id' => $option->id,
        ]);
    }

    return $order;
}

// ─── Menu options, not item names ────────────────────────────────────────────

it('targets the option that was bought, not just the dish', function () {
    $jollof = MenuItem::factory()->create(['name' => 'Jollof Rice']);
    $large = MenuItemOption::factory()->create(['menu_item_id' => $jollof->id, 'option_label' => 'Large']);
    $small = MenuItemOption::factory()->create(['menu_item_id' => $jollof->id, 'option_label' => 'Small']);

    orderPlacedAt('+233241111111', option: $large);
    orderPlacedAt('+233242222222', option: $small);

    // The whole point: "bought Jollof" is not the useful question. "Bought the
    // Large" is a different dish at a different price to a different person.
    expect(targetedPhones(['menu_item_option_ids' => [$large->id]]))->toBe(['+233241111111']);
    expect(targetedPhones(['menu_item_option_ids' => [$small->id]]))->toBe(['+233242222222']);
});

it('still lets you target every version of a dish', function () {
    $jollof = MenuItem::factory()->create(['name' => 'Jollof Rice']);
    $large = MenuItemOption::factory()->create(['menu_item_id' => $jollof->id]);
    $small = MenuItemOption::factory()->create(['menu_item_id' => $jollof->id]);

    orderPlacedAt('+233241111111', option: $large);
    orderPlacedAt('+233242222222', option: $small);

    $both = targetedPhones(['menu_item_ids' => [$jollof->id]]);

    expect($both)->toContain('+233241111111')->toContain('+233242222222');
});

it('matches an old order by dish even after its option was deleted', function () {
    /*
     * menu_item_option_id is nullable and set to null when an option is
     * deleted, so option-level history really can vanish from old orders. The
     * item-level filter is the net that never loses it, which is why both are
     * kept rather than one replacing the other.
     */
    $jollof = MenuItem::factory()->create();
    $option = MenuItemOption::factory()->create(['menu_item_id' => $jollof->id]);

    orderPlacedAt('+233241111111', option: $option);
    OrderItem::query()->update(['menu_item_option_id' => null]);

    expect(targetedPhones(['menu_item_option_ids' => [$option->id]]))->toBe([]);
    expect(targetedPhones(['menu_item_ids' => [$jollof->id]]))->toBe(['+233241111111']);
});

it('serves options labelled with the dish they belong to', function () {
    $jollof = MenuItem::factory()->create(['name' => 'Jollof Rice']);
    MenuItemOption::factory()->create([
        'menu_item_id' => $jollof->id,
        'option_label' => 'Large',
        'display_name' => null,
    ]);

    $admin = User::factory()->create(['phone' => '+233200000031']);
    $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'api'));
    \Spatie\Permission\Models\Role::findByName('admin', 'api')
        ->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('manage_campaigns', 'api'));

    $this->actingAs($admin, 'sanctum')
        ->getJson('/v1/admin/campaigns/audience-options')
        ->assertOk()
        // Never a bare "Large" with nothing to attach it to. Matched as a
        // fragment rather than by index because MenuItemFactory creates a
        // default option of its own, so position here is not ours to assume.
        ->assertJsonFragment(['label' => 'Jollof Rice — Large', 'group' => 'Jollof Rice']);
});

// ─── Primary branch ──────────────────────────────────────────────────────────

it('puts somebody in the branch they buy at most, not every branch they have visited', function () {
    $ashaiman = Branch::factory()->create(['name' => 'Ashaiman']);
    $mother = Branch::factory()->create(['name' => 'Mother Kitchen']);

    // Three at Ashaiman, one at Mother Kitchen. Ashaiman is theirs.
    orderPlacedAt('+233241111111', $ashaiman, attributes: ['created_at' => now()->subDays(9)]);
    orderPlacedAt('+233241111111', $ashaiman, attributes: ['created_at' => now()->subDays(8)]);
    orderPlacedAt('+233241111111', $ashaiman, attributes: ['created_at' => now()->subDays(7)]);
    orderPlacedAt('+233241111111', $mother, attributes: ['created_at' => now()->subDays(1)]);

    expect(targetedPhones(['primary_branch_ids' => [$ashaiman->id]]))->toBe(['+233241111111']);
    expect(targetedPhones(['primary_branch_ids' => [$mother->id]]))->toBe([]);

    // "Ever ordered at" still catches them at both — that is the difference.
    expect(targetedPhones(['branch_ids' => [$mother->id]]))->toBe(['+233241111111']);
});

it('breaks a tie with the branch they were at last', function () {
    $ashaiman = Branch::factory()->create();
    $mother = Branch::factory()->create();

    orderPlacedAt('+233241111111', $ashaiman, attributes: ['created_at' => now()->subDays(9)]);
    orderPlacedAt('+233241111111', $mother, attributes: ['created_at' => now()->subDay()]);

    expect(targetedPhones(['primary_branch_ids' => [$mother->id]]))->toBe(['+233241111111']);
    expect(targetedPhones(['primary_branch_ids' => [$ashaiman->id]]))->toBe([]);
});

it('can ignore a primary branch built on too few orders', function () {
    // One order makes a "primary" branch that means nothing.
    $ashaiman = Branch::factory()->create();
    orderPlacedAt('+233241111111', $ashaiman);

    expect(targetedPhones(['primary_branch_ids' => [$ashaiman->id]]))->toBe(['+233241111111']);
    expect(targetedPhones([
        'primary_branch_ids' => [$ashaiman->id],
        'primary_branch_min_orders' => 3,
    ]))->toBe([]);
});

it('finds people who have never ordered anywhere else', function () {
    $ashaiman = Branch::factory()->create();
    $mother = Branch::factory()->create();

    orderPlacedAt('+233241111111', $ashaiman);
    orderPlacedAt('+233241111111', $ashaiman);

    orderPlacedAt('+233242222222', $ashaiman);
    orderPlacedAt('+233242222222', $ashaiman);
    orderPlacedAt('+233242222222', $mother);

    // Both buy mostly at Ashaiman; only one has never been anywhere else.
    expect(targetedPhones(['primary_branch_ids' => [$ashaiman->id]]))
        ->toContain('+233241111111')->toContain('+233242222222');

    expect(targetedPhones(['only_branch_ids' => [$ashaiman->id]]))->toBe(['+233241111111']);
});

it('narrows rather than widens when branch and dish rules are stacked', function () {
    $ashaiman = Branch::factory()->create();
    $mother = Branch::factory()->create();
    $item = MenuItem::factory()->create();
    $option = MenuItemOption::factory()->create(['menu_item_id' => $item->id]);

    orderPlacedAt('+233241111111', $ashaiman, $option);
    orderPlacedAt('+233242222222', $mother, $option);

    expect(targetedPhones([
        'primary_branch_ids' => [$ashaiman->id],
        'menu_item_option_ids' => [$option->id],
    ]))->toBe(['+233241111111']);
});
