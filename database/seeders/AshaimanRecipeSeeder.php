<?php

namespace Database\Seeders;

use App\Models\Inventory\Item;
use App\Models\Inventory\Recipe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A recipe for every sellable option on the Ashaiman menu, so that a real sale
 * deducts real stock.
 *
 * Why every option and not every item: deduction resolves by
 * `menu_item_option_id` (RecipeDeductionService::resolveRecipe), so "Plain /
 * Assorted / Seafood Jollof" are three recipes, not one. The Ashaiman menu is 41
 * items but 59 options, and an option with no recipe deducts NOTHING, silently.
 * That silence is exactly what this seeder exists to remove.
 *
 * Recipes are written global (`branch_id = null`). That is not a shortcut:
 * `menu_items.branch_id` already scopes every one of these options to Ashaiman,
 * so a global recipe here is inherently Ashaiman-only. A branch override would
 * add a second row that resolves identically.
 *
 * The catalogue carries two parallel sets of items - seven created by hand
 * through the portal, the rest from InventoryMenuCatalogSeeder's demo list.
 * Where they overlap these recipes name the one the kitchen actually counts:
 * `Parboiled Rice`, not `Long Grain Rice`. `Frying Oil (Bulk)` is the frying
 * oil; the hand-created `Vegetable Oil` is not used and its balance is stale.
 *
 * QUANTITIES ARE IN THE ITEM'S BASE UNIT. There is no unit conversion in the
 * deduction path - the MVP takes the number as given. Rice is kg, so a portion
 * is 0.25, never 250. Eggs and the bottled drinks are stocked by the CRATE, so a
 * single egg is a fraction of one; see the pack-size constants below, which are
 * the assumptions most worth checking before this goes anywhere near a real day.
 *
 * Idempotent. Keyed on (option, null branch), and ingredient lines are replaced
 * wholesale, so re-running converges rather than duplicating.
 *
 * Guarded. Option ids are production-specific; every row carries the label it
 * expects to find, and a row whose option has drifted is skipped with a warning
 * rather than silently attached to a different dish.
 */
class AshaimanRecipeSeeder extends Seeder
{
    /** The branch these options belong to. */
    private const BRANCH = 'Ashaiman';

    // ── Pack sizes ───────────────────────────────────────────────────────────
    // Items stocked by the crate but sold by the unit. VERIFY THESE - they are
    // the difference between a sane depletion rate and a nonsensical one.
    private const PER_EGG = 0.0333;    // 30 eggs to a crate

    private const PER_COKE = 0.0833;   // 12 x 450 ml

    private const PER_FANTA = 0.0417;  // 24 x 330 ml

    private const PER_MALT = 0.0417;   // 24 cans

    private const PER_WATER = 0.0667;  // 15 x 500 ml

    // ── Components ───────────────────────────────────────────────────────────
    // Composed rather than repeated, so "what goes into jollof" is stated once
    // and every jollof dish inherits a correction to it.

    /** Packaging a hot plate always leaves in. */
    private const PACK_HOT = [
        'Takeaway Pack (Large)' => 1,
        'Carrier Bags' => 1,
        'Disposable Cutlery Set' => 1,
        'Serviettes' => 2,
    ];

    /** The economy packs go out in the smaller box. */
    private const PACK_ECONOMY = [
        'Takeaway Pack (Medium)' => 1,
        'Carrier Bags' => 1,
        'Disposable Cutlery Set' => 1,
        'Serviettes' => 1,
    ];

    /** A side or an extra, not a full plate. */
    private const PACK_SIDE = [
        'Takeaway Pack (Medium)' => 1,
        'Carrier Bags' => 1,
        'Serviettes' => 1,
    ];

    /** One portion of fried rice. */
    private const FRIED_RICE = [
        'Parboiled Rice' => 0.25,
        'Frying Oil (Bulk)' => 0.03,
        'Onions' => 0.03,
        'Carrots' => 0.03,
        'Green Peas' => 0.03,
        'Spring Onions' => 0.01,
        'Eggs' => self::PER_EGG,
        'Seasoning Cubes' => 1,
        'Salt' => 0.002,
        'Black Pepper (Ground)' => 0.05,
    ];

    /** One portion of jollof. */
    private const JOLLOF = [
        'Parboiled Rice' => 0.25,
        'Frying Oil (Bulk)' => 0.03,
        'Onions' => 0.04,
        'Tomatoes (Fresh)' => 0.06,
        'Tomato Paste' => 0.04,
        'Green Bell Pepper' => 0.02,
        'Scotch Bonnet Pepper' => 0.01,
        'Ginger (Fresh)' => 0.005,
        'Garlic (Fresh)' => 0.005,
        'Seasoning Cubes' => 1,
        'Salt' => 0.002,
        'Thyme (Dried)' => 0.05,
        'Bay Leaves' => 0.02,
    ];

    /** One portion of stir-fried noodles. */
    private const NOODLES = [
        'Instant Noodles' => 2,
        'Frying Oil (Bulk)' => 0.03,
        'Onions' => 0.03,
        'Carrots' => 0.03,
        'Green Bell Pepper' => 0.02,
        'Spring Onions' => 0.01,
        'Cabbage' => 0.03,
        'Seasoning Cubes' => 1,
        'Salt' => 0.002,
    ];

    /** What makes a dish "assorted" - the mixed protein folded through it. */
    private const ASSORTED = [
        'Chicken Thighs (Bone-in)' => 0.08,
        'Beef (Diced)' => 0.05,
        'Eggs' => self::PER_EGG,
    ];

    /** What makes a dish "seafood". */
    private const SEAFOOD = [
        'Shrimp (Peeled)' => 0.06,
        'Squid Rings' => 0.05,
    ];

    /** The Kɔkɔɔ ladle that comes with the sharing platters. */
    private const KOKOO = [
        'Kɔkɔɔ Sauce' => 0.15,
    ];

    private const FRIED_EGG = [
        'Eggs' => self::PER_EGG,
        'Frying Oil (Bulk)' => 0.01,
    ];

    private const BANKU_BALL = [
        'Corn Dough (Banku)' => 0.25,
        'Cassava Dough' => 0.10,
        'Salt' => 0.002,
    ];

    /** The "peppered" in peppered chicken. */
    private const PEPPER_SAUCE = [
        'Scotch Bonnet Pepper' => 0.02,
        'Onions' => 0.02,
        'Frying Oil (Bulk)' => 0.02,
        'Seasoning Cubes' => 0.5,
    ];

    /** Everything in a wrap except the protein. */
    private const WRAP_BASE = [
        'Tortilla Wraps' => 1,
        'Lettuce' => 0.03,
        'Cabbage' => 0.03,
        'Cucumber' => 0.02,
        'Mayonaise' => 0.02,
        'Seasoning Cubes' => 0.5,
        'Frying Oil (Bulk)' => 0.01,
        'Foil Wrap' => 1,
        'Carrier Bags' => 1,
        'Serviettes' => 1,
    ];

    /**
     * Items the menu needs that the catalogue does not carry yet.
     * name => [unit symbol, category, storage, reorder level, unit cost GHS]
     */
    private const MISSING_ITEMS = [
        'B B Cocktail' => ['pc', 'Beverages', 'dry', 50, 14.0],
    ];

    public function run(): void
    {
        $branchId = DB::table('branches')->where('name', self::BRANCH)->value('id');
        if (! $branchId) {
            $this->command?->error('No "'.self::BRANCH.'" branch - nothing seeded.');

            return;
        }

        $this->ensureMissingItems();

        /** @var array<string, object{id:int, base_unit_id:int}> $items */
        $items = Item::query()
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'base_unit_id'])
            ->keyBy('name');

        // Every option on this branch's menu, with the label each recipe expects
        // to be attached to, so a drifted id is caught rather than mis-applied.
        $options = DB::table('menu_item_options as o')
            ->join('menu_items as m', 'm.id', '=', 'o.menu_item_id')
            ->where('m.branch_id', $branchId)
            ->whereNull('o.deleted_at')
            ->whereNull('m.deleted_at')
            ->get(['o.id', 'o.option_key', 'm.name as item_name'])
            ->keyBy('id');

        $written = 0;
        $skipped = 0;
        $missing = [];

        foreach ($this->recipes() as $optionId => [$expected, $ingredients]) {
            $option = $options->get($optionId);

            if (! $option) {
                $this->command?->warn("Option {$optionId} ({$expected}) is not on the {$this->branchLabel()} menu - skipped.");
                $skipped++;

                continue;
            }

            if (! $this->matches($option, $expected)) {
                $this->command?->warn(
                    "Option {$optionId} is \"{$option->item_name} / {$option->option_key}\", ".
                    "expected \"{$expected}\" - skipped rather than attached to the wrong dish."
                );
                $skipped++;

                continue;
            }

            $lines = [];
            foreach ($ingredients as $name => $qty) {
                $item = $items->get($name);
                if (! $item) {
                    $missing[$name] = true;

                    continue;
                }
                $lines[] = [
                    'item_id' => (int) $item->id,
                    // Base unit, always. The deduction path does not convert.
                    'unit_id' => (int) $item->base_unit_id,
                    'quantity' => round((float) $qty, 4),
                ];
            }

            if ($lines === []) {
                $this->command?->warn("Option {$optionId} ({$expected}) resolved to no known items - skipped.");
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($optionId, $lines) {
                $recipe = Recipe::withTrashed()->firstOrNew([
                    'menu_item_option_id' => $optionId,
                    'branch_id' => null,
                ]);

                // Set directly rather than through fill(): `deleted_at` is not
                // fillable, so mass assignment would silently leave a
                // soft-deleted recipe deleted while reporting it written.
                $recipe->deleted_at = null;
                $recipe->is_default = true;
                $recipe->status = 'locked';
                $recipe->yield_qty = 1;
                $recipe->version = (int) ($recipe->version ?? 0) + 1;
                $recipe->save();

                // Replace wholesale so a re-run converges on this definition
                // instead of accumulating stale lines beside it.
                $recipe->ingredients()->delete();
                foreach ($lines as $line) {
                    $recipe->ingredients()->create($line);
                }
            });

            $written++;
        }

        $this->command?->info("Ashaiman recipes: {$written} written, {$skipped} skipped.");

        if ($missing !== []) {
            $this->command?->warn('Unknown inventory items, silently left out of recipes: '.implode(', ', array_keys($missing)));
        }

        $this->reportUnrecipedOptions($options);
    }

    /** Options on the menu that still deduct nothing. The number that matters. */
    private function reportUnrecipedOptions(\Illuminate\Support\Collection $options): void
    {
        $covered = DB::table('inventory_recipes')
            ->whereNull('deleted_at')
            ->pluck('menu_item_option_id')
            ->flip();

        $bare = $options->reject(fn ($o) => $covered->has($o->id));

        if ($bare->isEmpty()) {
            $this->command?->info('Every option on the '.$this->branchLabel().' menu now has a recipe.');

            return;
        }

        $this->command?->warn($bare->count().' option(s) still have NO recipe and will deduct nothing:');
        foreach ($bare as $o) {
            $this->command?->warn("  [{$o->id}] {$o->item_name} / {$o->option_key}");
        }
    }

    private function branchLabel(): string
    {
        return self::BRANCH;
    }

    /** Does the live option still look like the dish this recipe was written for? */
    private function matches(object $option, string $expected): bool
    {
        [$name, $key] = array_pad(explode(' / ', $expected, 2), 2, '');

        return Str::lower(trim($option->option_key)) === Str::lower(trim($key))
            && Str::lower(trim($option->item_name)) === Str::lower(trim($name));
    }

    /** Create the handful of catalogue items the menu needs and lacks. */
    private function ensureMissingItems(): void
    {
        $units = DB::table('inventory_units')->pluck('id', 'symbol');

        foreach (self::MISSING_ITEMS as $name => [$symbol, $category, $storage, $reorder, $cost]) {
            if (Item::where('name', $name)->exists()) {
                continue;
            }

            $unitId = $units[$symbol] ?? null;
            if (! $unitId) {
                $this->command?->warn("Cannot create {$name}: no unit '{$symbol}'.");

                continue;
            }

            $categoryId = DB::table('inventory_categories')->where('name', $category)->value('id');

            Item::create([
                'sku' => $this->nextSku(),
                'name' => $name,
                'category_id' => $categoryId,
                'base_unit_id' => $unitId,
                'storage_type' => $storage,
                'reorder_level' => $reorder,
                // Below reorder, not above it - the stock bands only read
                // correctly when the critical minimum sits under the trigger.
                'min_threshold' => round($reorder * 0.4, 2),
                'is_active' => true,
                'is_consumable' => true,
            ]);

            $this->command?->info("Created missing item: {$name} ({$symbol}).");
        }
    }

    /** Mirrors the sequential ITM-000001 scheme the API assigns. */
    private function nextSku(): string
    {
        $max = (int) DB::table('inventory_items')
            ->where('sku', 'like', 'ITM-%')
            ->selectRaw('MAX(CAST(SUBSTRING(sku, 5) AS INTEGER)) as m')
            ->value('m');

        return 'ITM-'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    // ── Composition helpers ──────────────────────────────────────────────────

    /** @param  array<string,float>  ...$parts */
    private function merge(array ...$parts): array
    {
        $out = [];
        foreach ($parts as $part) {
            foreach ($part as $name => $qty) {
                $out[$name] = round(($out[$name] ?? 0) + $qty, 4);
            }
        }

        return $out;
    }

    /** @param  array<string,float>  $part */
    private function scale(array $part, float $factor): array
    {
        return array_map(fn ($q) => round($q * $factor, 4), $part);
    }

    /** n plain fried drumsticks, roughly 120 g a piece. */
    private function drums(float $n): array
    {
        return [
            'Chicken Drumsticks' => round(0.12 * $n, 4),
            'Frying Oil (Bulk)' => round(0.01 * $n, 4),
            'Seasoning Cubes' => round(0.2 * $n, 4),
            'Salt' => round(0.001 * $n, 4),
        ];
    }

    /** n crumbed "special crunch" drumsticks. */
    private function crunchDrums(float $n): array
    {
        return $this->merge($this->drums($n), [
            'All-Purpose Flour' => round(0.02 * $n, 4),
            'Breadcrumbs' => round(0.015 * $n, 4),
            'Eggs' => round(self::PER_EGG / 3 * $n, 4), // one egg washes ~3 pieces
        ]);
    }

    /** n battered "juicy fried" drumsticks - no crumb. */
    private function juicyDrums(float $n): array
    {
        return $this->merge($this->drums($n), [
            'All-Purpose Flour' => round(0.025 * $n, 4),
            'Black Pepper (Ground)' => round(0.05 * $n, 4),
        ]);
    }

    /** $fish = 1 whole tilapia, 0.5 a half. */
    private function tilapia(float $fish): array
    {
        return $this->scale([
            'Tilapia (Whole, Large)' => 1,
            'Frying Oil (Bulk)' => 0.03,
            'Ginger (Fresh)' => 0.01,
            'Garlic (Fresh)' => 0.01,
            'Scotch Bonnet Pepper' => 0.02,
            'Seasoning Cubes' => 1,
            'Shito (Pepper Sauce)' => 0.05,
        ], $fish);
    }

    /** $bird = 1 whole rotisserie chicken, 0.5 a half cut. */
    private function rotisserie(float $bird): array
    {
        return $this->scale([
            'Whole Chicken (Rotisserie)' => 1,
            'Seasoning Cubes' => 2,
            'Thyme (Dried)' => 0.1,
            'Black Pepper (Ground)' => 0.1,
            'Garlic (Fresh)' => 0.015,
            'Ginger (Fresh)' => 0.015,
            'Salt' => 0.005,
            'Butter' => 0.02,
        ], $bird);
    }

    // ── The menu ─────────────────────────────────────────────────────────────

    /**
     * option id => [ "Menu Item Name / option_key", ingredients ]
     *
     * @return array<int, array{0:string, 1:array<string,float>}>
     */
    private function recipes(): array
    {
        return [
            // ── Combos ───────────────────────────────────────────────────────
            16 => ['Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / fried-rice',
                $this->merge(self::FRIED_RICE, $this->drums(3), self::PACK_HOT)],
            52 => ['Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / jollof-rice',
                $this->merge(self::JOLLOF, $this->drums(3), self::PACK_HOT)],
            59 => ['Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / noodles',
                $this->merge(self::NOODLES, $this->drums(3), self::PACK_HOT)],

            72 => ['Jollof + Chicken + Fried Egg / standard',
                $this->merge(self::JOLLOF, $this->drums(2), self::FRIED_EGG, self::PACK_HOT)],
            73 => ['Fried Rice + Chicken + Fried Egg / standard',
                $this->merge(self::FRIED_RICE, $this->drums(2), self::FRIED_EGG, self::PACK_HOT)],

            // Sharing platters - a double rice portion, not a single.
            21 => ['Fried Rice / Jollof Rice + 7 Drums + Kɔkɔɔ / fried-rice',
                $this->merge($this->scale(self::FRIED_RICE, 2), $this->drums(7), self::KOKOO, self::PACK_HOT)],
            51 => ['Fried Rice / Jollof Rice + 7 Drums + Kɔkɔɔ / jollof-rice',
                $this->merge($this->scale(self::JOLLOF, 2), $this->drums(7), self::KOKOO, self::PACK_HOT)],
            54 => ['Assorted Fried Rice / Noodles + 7 Drums + Kɔkɔɔ / assorted-fried-rice',
                $this->merge($this->scale(self::FRIED_RICE, 2), $this->scale(self::ASSORTED, 2), $this->drums(7), self::KOKOO, self::PACK_HOT)],
            55 => ['Assorted Fried Rice / Noodles + 7 Drums + Kɔkɔɔ / assorted-noodles',
                $this->merge($this->scale(self::NOODLES, 2), $this->scale(self::ASSORTED, 2), $this->drums(7), self::KOKOO, self::PACK_HOT)],

            42 => ['Assorted Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / assorted-fried-rice',
                $this->merge(self::FRIED_RICE, self::ASSORTED, $this->drums(3), self::PACK_HOT)],
            43 => ['Assorted Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / assorted-jollof-rice',
                $this->merge(self::JOLLOF, self::ASSORTED, $this->drums(3), self::PACK_HOT)],
            44 => ['Assorted Fried Rice / Jollof Rice / Noodles + 3 Drumsticks / assorted-noodles',
                $this->merge(self::NOODLES, self::ASSORTED, $this->drums(3), self::PACK_HOT)],

            60 => ['Assorted Fried Rice / Jollof Rice / Noodles + Full Chicken + Kɔkɔɔ / assorted-fried-rice',
                $this->merge($this->scale(self::FRIED_RICE, 2), $this->scale(self::ASSORTED, 2), $this->rotisserie(1), self::KOKOO, self::PACK_HOT)],
            61 => ['Assorted Fried Rice / Jollof Rice / Noodles + Full Chicken + Kɔkɔɔ / assorted-jollof-rice',
                $this->merge($this->scale(self::JOLLOF, 2), $this->scale(self::ASSORTED, 2), $this->rotisserie(1), self::KOKOO, self::PACK_HOT)],
            62 => ['Assorted Fried Rice / Jollof Rice / Noodles + Full Chicken + Kɔkɔɔ / assorted-noodles',
                $this->merge($this->scale(self::NOODLES, 2), $this->scale(self::ASSORTED, 2), $this->rotisserie(1), self::KOKOO, self::PACK_HOT)],

            74 => ['Banku with half Grilled Tilapia & Egg / standard',
                $this->merge(self::BANKU_BALL, $this->tilapia(0.5), self::FRIED_EGG, self::PACK_HOT)],

            // Economy packs - a smaller plate in the smaller box.
            75 => ['Fried Rice + Pepper Chicken  (Economy Pack) / standard',
                $this->merge($this->scale(self::FRIED_RICE, 0.8), $this->drums(2), self::PEPPER_SAUCE, self::PACK_ECONOMY)],
            76 => ['Jollof + Peppered Chicken (Economy Pack) / standard',
                $this->merge($this->scale(self::JOLLOF, 0.8), $this->drums(2), self::PEPPER_SAUCE, self::PACK_ECONOMY)],
            77 => ['Noodles + Peppered Chicken (Economy Pack) / standard',
                $this->merge($this->scale(self::NOODLES, 0.8), $this->drums(2), self::PEPPER_SAUCE, self::PACK_ECONOMY)],
            // Duplicate menu rows of 76 and 75 respectively - same recipe, so
            // whichever the till happens to ring up still deducts correctly.
            78 => ['Jollof + Peppered Chicken (Economy Pack) / standard',
                $this->merge($this->scale(self::JOLLOF, 0.8), $this->drums(2), self::PEPPER_SAUCE, self::PACK_ECONOMY)],
            79 => ['Fried Rice + Peppered Chicken  (Economy Pack) / standard',
                $this->merge($this->scale(self::FRIED_RICE, 0.8), $this->drums(2), self::PEPPER_SAUCE, self::PACK_ECONOMY)],

            80 => ['Extra Fried or Boiled Egg / standard',
                $this->merge(self::FRIED_EGG, self::PACK_SIDE)],
            81 => ['Extra Banku / standard',
                $this->merge(self::BANKU_BALL, self::PACK_SIDE)],
            82 => ['Fried Plantain / standard',
                $this->merge(['Ripe Plantain' => 2, 'Frying Oil (Bulk)' => 0.03, 'Salt' => 0.001], self::PACK_SIDE)],

            // ── Drinks - resale stock, one unit out of its crate ──────────────
            34 => ['Can Malt / standard', ['Can Malt' => self::PER_MALT]],
            37 => ['Fanta Cocktail / 330-ml', ['Fanta 330ml' => self::PER_FANTA]],
            35 => ['Coca Cola 450 ml / 450-ml', ['Coca Cola 450ml' => self::PER_COKE]],
            40 => ['Water (Bel Aqua) / standard', ['Bel Aqua Water 500ml' => self::PER_WATER]],
            39 => ['Don Simon / standard', ['Don Simon Juice (Large)' => 1]],
            64 => ['Don Simon (Small) / standard', ['Don Simon Juice (Small)' => 1]],
            41 => ['Ceres Red Grape / standard', ['Ceres Juice (Red Grape)' => 1]],
            38 => ['Ceres Mix / standard', ['Ceres Juice (Mixed)' => 1]],
            65 => ['Frutelli Cocktail / standard', ['Frutelli Cocktail' => 1]],
            32 => ['B B Cocktail / standard', ['B B Cocktail' => 1]],
            // Deliberately trivial: this row exists to be rung up and ignored,
            // and a single serviette makes it obvious in the ledger.
            63 => ['Test Menu Item -Do not fulfil / standard', ['Serviettes' => 1]],

            // ── Main delights ────────────────────────────────────────────────
            1 => ['Jollof Rice / plain', $this->merge(self::JOLLOF, self::PACK_HOT)],
            2 => ['Jollof Rice / assorted', $this->merge(self::JOLLOF, self::ASSORTED, self::PACK_HOT)],
            3 => ['Jollof Rice / seafood', $this->merge(self::JOLLOF, self::SEAFOOD, self::PACK_HOT)],
            4 => ['Fried Rice / plain', $this->merge(self::FRIED_RICE, self::PACK_HOT)],
            5 => ['Fried Rice / assorted', $this->merge(self::FRIED_RICE, self::ASSORTED, self::PACK_HOT)],
            6 => ['Fried Rice / seafood', $this->merge(self::FRIED_RICE, self::SEAFOOD, self::PACK_HOT)],
            45 => ['Noodles / assorted', $this->merge(self::NOODLES, self::ASSORTED, self::PACK_HOT)],
            46 => ['Noodles / seafood', $this->merge(self::NOODLES, self::SEAFOOD, self::PACK_HOT)],

            71 => ['Fried Rice - Chicken + Fried Egg / standard',
                $this->merge(self::FRIED_RICE, $this->drums(2), self::FRIED_EGG, self::PACK_HOT)],

            // Two menu rows share this name at different prices; the GHS 140 one
            // is read as the full fish against the GHS 110 half.
            9 => ['Banku With Grilled Tilapia / grilled-tilapia',
                $this->merge(self::BANKU_BALL, $this->tilapia(0.5), self::PACK_HOT)],
            70 => ['Banku With Grilled Tilapia / standard',
                $this->merge($this->scale(self::BANKU_BALL, 2), $this->tilapia(1), self::PACK_HOT)],

            83 => ['Full Tilapia / standard', $this->merge($this->tilapia(1), self::PACK_HOT)],

            // GUESSED CONTENTS - the two packages are priced like family platters
            // but the menu does not say what is in them. Confirm before trusting
            // their depletion.
            67 => ['BIG MAMA PACKAGE / standard',
                $this->merge($this->scale(self::JOLLOF, 2), self::FRIED_RICE, $this->scale(self::ASSORTED, 2),
                    $this->rotisserie(1), $this->tilapia(1), self::KOKOO, $this->scale(self::PACK_HOT, 2))],
            68 => ['MAMA’S DELIGHT PACKAGE / standard',
                $this->merge($this->scale(self::FRIED_RICE, 2), self::ASSORTED, $this->drums(5), self::KOKOO, self::PACK_HOT)],

            // ── Meat bites ───────────────────────────────────────────────────
            14 => ['Rotisserie Grilled Chicken / full', $this->merge($this->rotisserie(1), self::PACK_HOT)],
            15 => ['Rotisserie Grilled Chicken / half-cut', $this->merge($this->rotisserie(0.5), self::PACK_HOT)],
            47 => ['Drumsticks (Special Crunch) / 5-pieces', $this->merge($this->crunchDrums(5), self::PACK_HOT)],
            48 => ['Drumsticks (Special Crunch) / 10-pieces', $this->merge($this->crunchDrums(10), self::PACK_HOT)],
            49 => ['Drumsticks (Juicy Fried) / 5-pieces', $this->merge($this->juicyDrums(5), self::PACK_HOT)],
            50 => ['Drumsticks (Juicy Fried) / 10-pieces', $this->merge($this->juicyDrums(10), self::PACK_HOT)],
            66 => ['Extra Sea Food / standard', $this->merge(self::SEAFOOD, self::PACK_SIDE)],

            // ── Soft bites ───────────────────────────────────────────────────
            29 => ['Cedi Wraps / chicken', $this->merge(self::WRAP_BASE, ['Chicken Thighs (Bone-in)' => 0.10])],
            30 => ['Cedi Wraps / beef', $this->merge(self::WRAP_BASE, ['Beef (Diced)' => 0.10])],
            31 => ['Cedi Wraps / mix', $this->merge(self::WRAP_BASE, ['Chicken Thighs (Bone-in)' => 0.06, 'Beef (Diced)' => 0.06])],
        ];
    }
}
