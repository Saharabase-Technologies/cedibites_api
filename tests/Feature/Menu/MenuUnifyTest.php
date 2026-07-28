<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Branch Isolation, Phase 3 — one dish, many branches
|--------------------------------------------------------------------------
|
| menu_items carries a branch_id with UNIQUE(branch_id, slug), so the same dish
| is a different row with a different id at every branch. Everything downstream
| keys off those ids, which is why recipes stop deducting at a second branch,
| promos land at one branch only, and ratings reset.
|
| menu:unify picks one survivor per slug and repoints everything to it. These
| tests exist because that command rewrites foreign keys across nine tables and
| a mistake is not obvious from looking at it.
|
*/

/**
 * The same dish at a branch, with one standard option at a given price.
 */
function dishAt(Branch $branch, string $name, float $price, string $optionKey = 'standard'): MenuItem
{
    $item = MenuItem::factory()->create([
        'branch_id' => $branch->id,
        'name' => $name,
        'slug' => \Illuminate\Support\Str::slug($name),
    ]);

    // forceDelete, not delete: a soft-deleted row still holds
    // UNIQUE(menu_item_id, option_key), so the factory's own option would
    // collide with the one below.
    $item->options()->forceDelete();
    $item->options()->create([
        'option_key' => $optionKey,
        'option_label' => ucfirst($optionKey),
        'price' => $price,
        'display_order' => 0,
        'is_available' => true,
    ]);

    return $item->fresh(['options']);
}

beforeEach(function () {
    $this->ashaiman = Branch::factory()->create(['name' => 'Ashaiman']);
    $this->kasoa = Branch::factory()->create(['name' => 'Kasoa']);
});

describe('merging duplicates', function () {
    it('keeps one row and soft-deletes the rest', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::find($original->id))->not->toBeNull()
            ->and(MenuItem::find($copy->id))->toBeNull()
            ->and(MenuItem::withTrashed()->find($copy->id))->not->toBeNull();
    });

    it('records both branches as serving the surviving dish', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 50);

        $this->artisan('menu:unify')->assertSuccessful();

        // Contains rather than equals: the factory chain (menu item → category
        // → branch) quietly creates branches of its own, and every active
        // branch is served the whole menu. What matters is that the merge left
        // both real branches attached to the one surviving row.
        $branchIds = $original->fresh()->branches->pluck('id')->all();

        expect($branchIds)->toContain($this->ashaiman->id)
            ->and($branchIds)->toContain($this->kasoa->id);
    });

    it('preserves a differing branch price as an override', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 65);

        $this->artisan('menu:unify')->assertSuccessful();

        $optionId = $original->fresh(['options'])->options->first()->id;

        $override = DB::table('menu_item_option_branch_prices')
            ->where('menu_item_option_id', $optionId)
            ->where('branch_id', $this->kasoa->id)
            ->first();

        expect($override)->not->toBeNull()
            ->and((float) $override->price)->toBe(65.0);
    });

    it('writes no override when the price is the same', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 50);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(DB::table('menu_item_option_branch_prices')->count())->toBe(0);
    });

    it('carries across a size only one branch sells', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);
        $copy->options()->create([
            'option_key' => 'family',
            'option_label' => 'Family',
            'price' => 120,
            'display_order' => 1,
            'is_available' => true,
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        expect($original->fresh(['options'])->options->pluck('option_key')->sort()->values()->all())
            ->toBe(['family', 'standard']);
    });

    it('does nothing on a second run', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 65);

        $this->artisan('menu:unify')->assertSuccessful();
        $before = MenuItem::count();

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::count())->toBe($before)
            ->and(DB::table('menu_item_option_branch_prices')->count())->toBe(1);
    });

    it('writes nothing on a dry run', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 65);

        $this->artisan('menu:unify', ['--dry-run' => true])->assertSuccessful();

        expect(MenuItem::find($copy->id))->not->toBeNull()
            ->and(DB::table('menu_item_branches')->count())->toBe(0)
            ->and(DB::table('menu_item_option_branch_prices')->count())->toBe(0);
    });

    it('gives a dish that exists at one branch its pivot row', function () {
        $solo = dishAt($this->ashaiman, 'Banku', 40);

        $this->artisan('menu:unify')->assertSuccessful();

        // Without this row the dish would vanish from the menu entirely once
        // reads move off the legacy branch_id.
        expect($solo->fresh()->branches->pluck('id')->all())->toContain($this->ashaiman->id);
    });
});

describe('what the merge must not break', function () {
    it('leaves a past order reading exactly as it did', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 65);

        $order = Order::factory()->create(['branch_id' => $this->kasoa->id]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $copy->id,
            'menu_item_option_id' => $copy->options->first()->id,
            'quantity' => 2,
            'unit_price' => 65,
            'subtotal' => 130,
            'menu_item_snapshot' => ['name' => 'Jollof Rice'],
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        $line->refresh();

        expect((float) $line->unit_price)->toBe(65.0)
            ->and((float) $line->subtotal)->toBe(130.0)
            ->and($line->menu_item_snapshot['name'])->toBe('Jollof Rice');
    });

    it('repoints the order line to the surviving dish', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 65);

        $order = Order::factory()->create(['branch_id' => $this->kasoa->id]);
        $line = OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $copy->id,
            'menu_item_option_id' => $copy->options->first()->id,
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        expect($line->refresh()->menu_item_id)->toBe($original->id)
            ->and($line->menu_item_option_id)->toBe($original->fresh(['options'])->options->first()->id);
    });

    it('turns a second branch\'s recipe into a real per-branch override', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);

        // Each branch wrote its recipe "global" — which was only ever its own,
        // because the option ids belonged to that branch alone.
        $recipeId = DB::table('inventory_recipes')->insertGetId([
            'menu_item_option_id' => $copy->options->first()->id,
            'branch_id' => null,
            'is_default' => true,
            'status' => 'locked',
            'version' => 1,
            'yield_qty' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        $recipe = DB::table('inventory_recipes')->find($recipeId);

        expect((int) $recipe->menu_item_option_id)->toBe($original->fresh(['options'])->options->first()->id)
            ->and((int) $recipe->branch_id)->toBe($this->kasoa->id);
    });

    it('merges ratings and recomputes the average', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);

        DB::table('menu_item_ratings')->insert([
            ['customer_id' => Customer::factory()->create()->id, 'menu_item_id' => $original->id, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => Customer::factory()->create()->id, 'menu_item_id' => $copy->id, 'rating' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        $survivor = $original->fresh();

        expect($survivor->rating_count)->toBe(2)
            ->and((float) $survivor->rating)->toBe(4.0);
    });

    it('keeps one rating per customer when they rated the dish at both branches', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);
        $customer = Customer::factory()->create();

        DB::table('menu_item_ratings')->insert([
            ['customer_id' => $customer->id, 'menu_item_id' => $original->id, 'rating' => 2, 'created_at' => now()->subDay(), 'updated_at' => now()],
            ['customer_id' => $customer->id, 'menu_item_id' => $copy->id, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('menu:unify')->assertSuccessful();

        $ratings = DB::table('menu_item_ratings')->where('menu_item_id', $original->id)->get();

        expect($ratings)->toHaveCount(1)
            ->and((int) $ratings->first()->rating)->toBe(5); // the more recent one
    });

    it('does not duplicate a tag both branches carried', function () {
        $original = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $copy = dishAt($this->kasoa, 'Jollof Rice', 50);
        $tag = \App\Models\MenuTag::factory()->create();

        $original->tags()->attach($tag->id);
        $copy->tags()->attach($tag->id);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(DB::table('menu_item_menu_tag')->where('menu_item_id', $original->id)->count())->toBe(1);
    });
});

describe('reading the menu during and after the merge', function () {
    it('serves the branch its dishes before the merge has run', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Waakye', 40);

        $names = MenuItem::servedAt($this->ashaiman->id)->pluck('name')->all();

        expect($names)->toBe(['Jollof Rice']);
    });

    it('serves both branches the surviving dish after the merge', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 65);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::servedAt($this->ashaiman->id)->pluck('name')->all())->toBe(['Jollof Rice'])
            ->and(MenuItem::servedAt($this->kasoa->id)->pluck('name')->all())->toBe(['Jollof Rice']);
    });

    it('serves a dish that started at one branch to all of them', function () {
        // The model is one menu for the whole business: the same institution
        // behind several tills. A dish that happens to have been created at
        // Ashaiman is not an Ashaiman dish — it is on the menu. What differs by
        // branch is the price and whether today's pot has run out.
        dishAt($this->ashaiman, 'Banku', 40);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::servedAt($this->kasoa->id)->pluck('name')->all())->toBe(['Banku']);
    });

    it('answers the public menu endpoint per branch after the merge', function () {
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->kasoa, 'Jollof Rice', 65);
        dishAt($this->ashaiman, 'Banku', 40);

        $this->artisan('menu:unify')->assertSuccessful();

        $kasoa = $this->getJson("/v1/menu-items?branch_id={$this->kasoa->id}")
            ->assertSuccessful()
            ->json('data');

        expect(collect($kasoa)->pluck('name')->sort()->values()->all())
            ->toBe(['Banku', 'Jollof Rice']);
    });
});

describe('every branch serves the whole menu', function () {
    it('gives a branch with no menu of its own the full menu', function () {
        // The Test Branch symptom: created after the menu existed, so nothing
        // ever gave it one and its POS came up empty.
        dishAt($this->ashaiman, 'Jollof Rice', 50);
        dishAt($this->ashaiman, 'Banku', 40);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::servedAt($this->kasoa->id)->pluck('name')->sort()->values()->all())
            ->toBe(['Banku', 'Jollof Rice']);
    });

    it('does not switch a sold-out dish back on', function () {
        $dish = dishAt($this->ashaiman, 'Jollof Rice', 50);
        $this->artisan('menu:unify')->assertSuccessful();

        // A manager marks it sold out at Kasoa.
        $dish->branches()->syncWithoutDetaching([$this->kasoa->id => ['is_available' => false]]);

        // A later deploy re-runs the command.
        $this->artisan('menu:unify')->assertSuccessful();

        $atKasoa = $dish->fresh()->branches->firstWhere('id', $this->kasoa->id);
        expect((bool) $atKasoa->pivot->is_available)->toBeFalse();
    });

    it('skips branches that are not active', function () {
        $closed = Branch::factory()->create(['is_active' => false]);
        dishAt($this->ashaiman, 'Jollof Rice', 50);

        $this->artisan('menu:unify')->assertSuccessful();

        expect(MenuItem::servedAt($closed->id)->count())->toBe(0);
    });
});
