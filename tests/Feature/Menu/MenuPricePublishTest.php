<?php

use App\Enums\MenuPublicationStatus;
use App\Enums\Role as RoleEnum;
use App\Events\MenuUpdatedEvent;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\MenuPublication;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| New prices wait, then go live when the owner says
|--------------------------------------------------------------------------
|
| "I'll want a trigger for when we're ready to publish them." Changes are
| typed in ahead of time and nothing that sells sees them. One press puts them
| live, now or at a set time, and the till and the website reload.
|
*/

function priceAdmin(): User
{
    $user = User::factory()->create(['name' => 'Richard']);
    $user->assignRole(RoleEnum::Admin->value);

    return $user->fresh();
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    Event::fake([MenuUpdatedEvent::class]);

    $this->branch = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->admin = priceAdmin();

    $this->rotisserie = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Rotisserie Grilled Chicken',
    ]);
    $this->rotisserie->options()->delete();
    $this->full = MenuItemOption::create([
        'menu_item_id' => $this->rotisserie->id,
        'option_key' => 'full',
        'option_label' => 'Full',
        'display_name' => 'Full Rotisserie Grilled Chicken',
        'price' => 170,
        'display_order' => 0,
    ]);
    $this->half = MenuItemOption::create([
        'menu_item_id' => $this->rotisserie->id,
        'option_key' => 'half-cut',
        'option_label' => 'Half Cut',
        'display_name' => 'Half Cut Rotisserie Grilled Chicken',
        'price' => 90,
        'display_order' => 1,
    ]);

    $this->drumsticks = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Drumsticks (Special Crunch)',
    ]);
});

function draftPrice(MenuItemOption $option, mixed $value)
{
    return test()->putJson('/v1/admin/menu-drafts', [
        'menu_item_id' => $option->menu_item_id,
        'menu_item_option_id' => $option->id,
        'field' => 'price',
        'value' => $value,
    ]);
}

describe('drafts', function () {
    it('waits without touching the live price', function () {
        $this->actingAs($this->admin);

        $board = draftPrice($this->full, 200)->assertSuccessful()->json('data');

        expect((float) $this->full->fresh()->price)->toBe(170.0)
            ->and($board['waiting_count'])->toBe(1);

        $dish = collect($board['dishes'])->firstWhere('id', $this->rotisserie->id);
        $full = collect($dish['options'])->firstWhere('id', $this->full->id);

        expect($full['price'])->toEqual(170)
            ->and($full['drafts']['price']['value'])->toEqual(200);
    });

    it('keeps no draft equal to today, and clears on an empty value', function () {
        $this->actingAs($this->admin);

        draftPrice($this->full, 200);
        draftPrice($this->full, '170.00');
        expect(MenuDraft::count())->toBe(0);

        draftPrice($this->full, 200);
        draftPrice($this->full, null);
        expect(MenuDraft::count())->toBe(0);
    });

    it('refuses an option from another dish', function () {
        $this->actingAs($this->admin);

        $this->putJson('/v1/admin/menu-drafts', [
            'menu_item_id' => $this->drumsticks->id,
            'menu_item_option_id' => $this->full->id,
            'field' => 'price',
            'value' => 10,
        ])->assertUnprocessable();
    });

    it('needs a receipt name for a new option', function () {
        $this->actingAs($this->admin);

        $this->postJson('/v1/admin/menu-drafts/new-option', [
            'menu_item_id' => $this->drumsticks->id,
            'option_label' => '7 Wings',
            'price' => 65,
        ])->assertUnprocessable()->assertJsonValidationErrors('display_name');
    });

    it('is closed to a branch manager', function () {
        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::Manager->value);

        $this->actingAs($manager->fresh())->getJson('/v1/admin/menu-prices')->assertForbidden();
        $this->actingAs($manager->fresh())->postJson('/v1/admin/menu-publications', ['when' => 'now'])->assertForbidden();
    });
});

describe('publishing now', function () {
    it('puts every waiting change live at once, and logs it', function () {
        $this->actingAs($this->admin);

        draftPrice($this->full, 200);
        draftPrice($this->half, 110);

        $board = $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])
            ->assertSuccessful()
            ->json('data');

        expect((float) $this->full->fresh()->price)->toBe(200.0)
            ->and((float) $this->half->fresh()->price)->toBe(110.0)
            ->and(MenuDraft::count())->toBe(0)
            ->and($board['waiting_count'])->toBe(0);

        $last = $board['history'][0];
        expect($last['status'])->toBe('published')
            ->and($last['published_by'])->toBe('Richard')
            ->and($last['change_count'])->toBe(2)
            ->and($last['can_undo'])->toBeTrue()
            ->and(collect($last['changes'])->pluck('line_name')->all())
            ->toBe(['Full Rotisserie Grilled Chicken', 'Half Cut Rotisserie Grilled Chicken']);

        Event::assertDispatched(MenuUpdatedEvent::class, fn ($e) => $e->kind === 'published');
    });

    it('creates new options, renames and takes a dish off sale', function () {
        $this->actingAs($this->admin);
        $old = MenuItem::factory()->create(['name' => 'Banku With Grilled Tilapia']);

        $this->postJson('/v1/admin/menu-drafts/new-option', [
            'menu_item_id' => $this->drumsticks->id,
            'option_label' => '7 Wings',
            'display_name' => '7 Pieces of Special Crunch Wings',
            'price' => 65,
        ])->assertSuccessful();

        $this->putJson('/v1/admin/menu-drafts', [
            'menu_item_id' => $this->drumsticks->id,
            'field' => 'name',
            'value' => 'Drumsticks and Wings (Special Crunch)',
        ])->assertSuccessful();

        $this->putJson('/v1/admin/menu-drafts', [
            'menu_item_id' => $old->id,
            'field' => 'off_sale',
            'value' => true,
        ])->assertSuccessful();

        // Nothing yet: the wings are not an option anybody can buy.
        expect($this->drumsticks->options()->where('option_label', '7 Wings')->exists())->toBeFalse();

        $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->assertSuccessful();

        $wings = $this->drumsticks->options()->where('option_label', '7 Wings')->first();

        expect($wings)->not->toBeNull()
            ->and($wings->option_key)->toBe('7-wings')
            ->and($wings->display_name)->toBe('7 Pieces of Special Crunch Wings')
            ->and((float) $wings->price)->toBe(65.0)
            ->and($this->drumsticks->fresh()->name)->toBe('Drumsticks and Wings (Special Crunch)')
            ->and($old->fresh()->is_available)->toBeFalse();
    });

    it('reprices a basket filled before the switch', function () {
        $this->actingAs($this->admin);

        $cart = Cart::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $line = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'menu_item_id' => $this->rotisserie->id,
            'menu_item_option_id' => $this->full->id,
            'quantity' => 2,
            'unit_price' => 170,
            'subtotal' => 340,
        ]);

        $done = Cart::factory()->create(['branch_id' => $this->branch->id, 'status' => 'completed']);
        $old = CartItem::factory()->create([
            'cart_id' => $done->id,
            'menu_item_id' => $this->rotisserie->id,
            'menu_item_option_id' => $this->full->id,
            'quantity' => 1,
            'unit_price' => 170,
            'subtotal' => 170,
        ]);

        draftPrice($this->full, 200);
        $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->assertSuccessful();

        expect((float) $line->fresh()->unit_price)->toBe(200.0)
            ->and((float) $line->fresh()->subtotal)->toBe(400.0)
            ->and((float) $old->fresh()->unit_price)->toBe(170.0);
    });

    it('refuses when nothing is waiting', function () {
        $this->actingAs($this->admin)
            ->postJson('/v1/admin/menu-publications', ['when' => 'now'])
            ->assertUnprocessable();

        Event::assertNotDispatched(MenuUpdatedEvent::class);
    });
});

describe('a set time', function () {
    it('waits for its time, then carries what is waiting then', function () {
        $this->actingAs($this->admin);
        $this->travelTo(now()->setTime(20, 0));

        draftPrice($this->full, 200);

        $this->postJson('/v1/admin/menu-publications', [
            'when' => 'at',
            'publish_at' => now()->addDay()->setTime(6, 0)->toDateTimeString(),
        ])->assertSuccessful()->assertJsonPath('data.scheduled.scheduled_by', 'Richard');

        // Typed in after the time was set. It goes too.
        draftPrice($this->half, 110);

        $this->artisan('menu:publish-due')->assertSuccessful();
        expect((float) $this->full->fresh()->price)->toBe(170.0);

        $this->travelTo(now()->addDay()->setTime(6, 0, 30));
        $this->artisan('menu:publish-due')->assertSuccessful();

        $publication = MenuPublication::sole();
        expect($publication->status)->toBe(MenuPublicationStatus::Published)
            ->and($publication->published_by)->toBeNull()
            ->and($publication->change_count)->toBe(2)
            ->and((float) $this->full->fresh()->price)->toBe(200.0)
            ->and((float) $this->half->fresh()->price)->toBe(110.0);
    });

    it('refuses a time that has passed', function () {
        $this->actingAs($this->admin);
        draftPrice($this->full, 200);

        $this->postJson('/v1/admin/menu-publications', [
            'when' => 'at',
            'publish_at' => now()->subMinute()->toDateTimeString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('publish_at');
    });

    it('can be called off, leaving the changes waiting', function () {
        $this->actingAs($this->admin);
        draftPrice($this->full, 200);

        $id = $this->postJson('/v1/admin/menu-publications', [
            'when' => 'at',
            'publish_at' => now()->addHour()->toDateTimeString(),
        ])->json('data.scheduled.id');

        $this->postJson("/v1/admin/menu-publications/{$id}/cancel")->assertSuccessful();

        $this->travel(2)->hours();
        $this->artisan('menu:publish-due')->assertSuccessful();

        expect((float) $this->full->fresh()->price)->toBe(170.0)
            ->and(MenuDraft::count())->toBe(1);
    });

    it('goes early on Go live now, as the same publish', function () {
        $this->actingAs($this->admin);
        draftPrice($this->full, 200);

        $this->postJson('/v1/admin/menu-publications', [
            'when' => 'at',
            'publish_at' => now()->addHour()->toDateTimeString(),
        ]);

        $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->assertSuccessful();

        expect(MenuPublication::count())->toBe(1)
            ->and(MenuPublication::sole()->status)->toBe(MenuPublicationStatus::Published);

        $this->travel(2)->hours();
        $this->artisan('menu:publish-due')->assertSuccessful();
        Event::assertDispatchedTimes(MenuUpdatedEvent::class, 1);
    });

    it('is cancelled with a note when nothing is waiting at the time', function () {
        $this->actingAs($this->admin);
        draftPrice($this->full, 200);

        $this->postJson('/v1/admin/menu-publications', [
            'when' => 'at',
            'publish_at' => now()->addHour()->toDateTimeString(),
        ]);
        $this->deleteJson('/v1/admin/menu-drafts')->assertSuccessful();

        $this->travel(2)->hours();
        $this->artisan('menu:publish-due')->assertSuccessful();

        expect(MenuPublication::sole()->status)->toBe(MenuPublicationStatus::Cancelled)
            ->and(MenuPublication::sole()->note)->toBe('Nothing was waiting when the time came.');
    });
});

describe('undo', function () {
    it('puts the old values back and removes the new options', function () {
        $this->actingAs($this->admin);

        $cart = Cart::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $line = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'menu_item_id' => $this->rotisserie->id,
            'menu_item_option_id' => $this->full->id,
            'quantity' => 1,
            'unit_price' => 170,
            'subtotal' => 170,
        ]);

        draftPrice($this->full, 200);
        $this->postJson('/v1/admin/menu-drafts/new-option', [
            'menu_item_id' => $this->drumsticks->id,
            'option_label' => '7 Wings',
            'display_name' => '7 Pieces of Special Crunch Wings',
            'price' => 65,
        ]);
        $id = $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->json('data.history.0.id');

        $board = $this->postJson("/v1/admin/menu-publications/{$id}/undo")->assertSuccessful()->json('data');

        expect((float) $this->full->fresh()->price)->toBe(170.0)
            ->and((float) $line->fresh()->unit_price)->toBe(170.0)
            ->and($this->drumsticks->options()->where('option_label', '7 Wings')->exists())->toBeFalse()
            ->and($board['history'][0]['status'])->toBe('undone')
            ->and($board['history'][0]['undone_by'])->toBe('Richard');

        Event::assertDispatched(MenuUpdatedEvent::class, fn ($e) => $e->kind === 'undone');
    });

    it('leaves a value somebody changed again since', function () {
        $this->actingAs($this->admin);

        draftPrice($this->full, 200);
        draftPrice($this->half, 110);
        $id = $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->json('data.history.0.id');

        // Somebody fixes the half straight from the menu editor.
        $this->half->update(['price' => 115]);

        $this->postJson("/v1/admin/menu-publications/{$id}/undo")->assertSuccessful();

        expect((float) $this->full->fresh()->price)->toBe(170.0)
            ->and((float) $this->half->fresh()->price)->toBe(115.0)
            ->and(MenuPublication::find($id)->note)->toBe('1 value was changed again since. It was left as it is.');
    });

    it('only undoes the latest publish', function () {
        $this->actingAs($this->admin);

        draftPrice($this->full, 200);
        $first = $this->postJson('/v1/admin/menu-publications', ['when' => 'now'])->json('data.history.0.id');

        draftPrice($this->half, 110);
        $this->postJson('/v1/admin/menu-publications', ['when' => 'now']);

        $this->postJson("/v1/admin/menu-publications/{$first}/undo")->assertUnprocessable();
        expect((float) $this->full->fresh()->price)->toBe(200.0);
    });
});
