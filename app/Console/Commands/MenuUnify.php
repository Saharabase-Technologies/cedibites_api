<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge the per-branch copies of each dish into one — the migrate step of the
 * menu unification (docs/BRANCH_ISOLATION_PLAN.md, Phase 3).
 *
 * `menu_items` carries a branch_id with UNIQUE(branch_id, slug), so "Jollof
 * Rice" at Ashaiman and at Kasoa are two rows with two ids and two sets of
 * options. Everything downstream keys off those ids, which is why:
 *
 *   - recipes stop deducting at a second branch (inventory_recipes keys on
 *     menu_item_option_id, and the second branch's option ids match nothing);
 *   - a promo attached to a dish only ever applies at one branch;
 *   - ratings reset, so a new branch shows every dish as unrated;
 *   - basket affinity counts the same pairing as unrelated per branch.
 *
 * This picks one survivor per slug, repoints every reference to it, preserves
 * each branch's price as an override, and records which branches serve the dish
 * in menu_item_branches.
 *
 * Safe by construction:
 *   - order_items and cart_items store menu_item_snapshot, unit_price and
 *     subtotal on the line, and every creation path fills them, so repointing
 *     cannot rewrite a past receipt or a revenue figure;
 *   - losers are soft-deleted, never hard-deleted;
 *   - --dry-run reports the whole plan and writes nothing;
 *   - idempotent: a second run finds no duplicate slugs and does nothing.
 *
 * Run --dry-run against a production copy and read the output before running it
 * for real. Dropping menu_items.branch_id is a separate migration that must not
 * run until this has.
 */
class MenuUnify extends Command
{
    protected $signature = 'menu:unify
                            {--dry-run : Report the merge plan without writing anything}';

    protected $description = 'Merge per-branch duplicates of each menu item into one global dish';

    private bool $dry = false;

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('DRY RUN — nothing will be written.');
            $this->newLine();
        }

        $groups = $this->duplicateSlugGroups();

        if ($groups->isEmpty()) {
            $this->info('No duplicate slugs. The menu is already unified, or there is only one branch.');
            $this->backfillPivotForSingletons();

            return self::SUCCESS;
        }

        $this->info($groups->count().' dish(es) exist at more than one branch.');
        $this->newLine();

        $rows = [];

        foreach ($groups as $slug => $items) {
            $rows[] = $this->mergeGroup($slug, $items);
        }

        $this->table(
            ['Slug', 'Survivor', 'Merged', 'Price overrides', 'Recipes repointed', 'Ratings merged'],
            $rows,
        );

        $this->backfillPivotForSingletons();

        if ($this->warnings !== []) {
            $this->newLine();
            $this->warn('Needs a human eye:');
            foreach ($this->warnings as $warning) {
                $this->line('  - '.$warning);
            }
        }

        $this->newLine();
        $this->info($this->dry
            ? 'Dry run complete. Re-run without --dry-run to apply.'
            : 'Merge complete. Verify, then run the migration that drops menu_items.branch_id.');

        return self::SUCCESS;
    }

    /**
     * Slugs held by more than one branch, oldest row first so the survivor is
     * the original.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, MenuItem>>
     */
    private function duplicateSlugGroups(): \Illuminate\Support\Collection
    {
        return MenuItem::query()
            ->with(['options' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')
            ->get()
            ->groupBy('slug')
            ->filter(fn ($items) => $items->count() > 1);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MenuItem>  $items
     * @return array<int, string|int>
     */
    private function mergeGroup(string $slug, \Illuminate\Support\Collection $items): array
    {
        $survivor = $items->first();
        $losers = $items->slice(1);

        $overrides = 0;
        $recipes = 0;
        $ratings = 0;

        DB::transaction(function () use ($survivor, $losers, &$overrides, &$recipes, &$ratings) {
            // The survivor's own branch serves it.
            $this->serveAt($survivor->id, (int) $survivor->branch_id, (bool) $survivor->is_available);

            foreach ($losers as $loser) {
                $this->serveAt($survivor->id, (int) $loser->branch_id, (bool) $loser->is_available);

                $optionMap = $this->mapOptions($survivor, $loser, $overrides);

                $recipes += $this->repointRecipes($optionMap, (int) $loser->branch_id);
                $this->repointOptionPrices($optionMap, (int) $loser->branch_id);
                $this->repointLineItems($loser->id, $survivor->id, $optionMap);
                $ratings += $this->repointRatings($loser->id, $survivor->id);
                $this->repointPivot('promo_menu_items', 'promo_id', $loser->id, $survivor->id);
                $this->repointPivot('menu_item_menu_tag', 'menu_tag_id', $loser->id, $survivor->id);
                $this->repointPivot('menu_item_menu_add_on', 'menu_add_on_id', $loser->id, $survivor->id);

                if (! $this->dry) {
                    $loser->options()->delete();
                    $loser->delete();
                }
            }

            if (! $this->dry) {
                $this->recomputeRating($survivor->id);
            }
        }, 3);

        return [
            $slug,
            "#{$survivor->id} (branch {$survivor->branch_id})",
            $losers->count(),
            $overrides,
            $recipes,
            $ratings,
        ];
    }

    /**
     * Record that a branch serves this dish.
     */
    private function serveAt(int $menuItemId, int $branchId, bool $isAvailable): void
    {
        if ($this->dry) {
            return;
        }

        DB::table('menu_item_branches')->updateOrInsert(
            ['menu_item_id' => $menuItemId, 'branch_id' => $branchId],
            ['is_available' => $isAvailable, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * Pair each of the loser's options with the survivor's by option_key, and
     * preserve the loser branch's price as an override wherever it differs.
     *
     * An option_key the survivor does not have is created on the survivor
     * rather than dropped — losing a size a branch actually sells would be
     * worse than carrying one it does not.
     *
     * @return array<int, int> loser option id => survivor option id
     */
    private function mapOptions(MenuItem $survivor, MenuItem $loser, int &$overrides): array
    {
        $survivorOptions = $survivor->options->keyBy('option_key');
        $map = [];

        foreach ($loser->options as $loserOption) {
            $match = $survivorOptions->get($loserOption->option_key);

            if (! $match) {
                $this->warnings[] = "{$loser->slug}: branch {$loser->branch_id} has option "
                    ."\"{$loserOption->option_key}\" that branch {$survivor->branch_id} does not — copied onto the survivor.";

                if ($this->dry) {
                    continue;
                }

                // withTrashed + firstOrNew: a soft-deleted option still holds
                // UNIQUE(menu_item_id, option_key), so creating blindly would
                // collide with a size the survivor's branch once sold and
                // withdrew. Revive that row instead.
                $match = $survivor->options()->withTrashed()
                    ->firstOrNew(['option_key' => $loserOption->option_key]);
                $match->fill([
                    'option_label' => $loserOption->option_label,
                    'display_name' => $loserOption->display_name,
                    'price' => $loserOption->price,
                    'display_order' => $loserOption->display_order,
                    'is_available' => $loserOption->is_available,
                    'deleted_at' => null,
                ]);
                $match->save();

                $survivorOptions->put($match->option_key, $match);
            }

            $map[$loserOption->id] = $match->id;

            // A different price at that branch is not a conflict — it is the
            // branch price, and it becomes an override on the survivor.
            if ((float) $loserOption->price !== (float) $match->price) {
                $overrides++;

                if (! $this->dry) {
                    DB::table('menu_item_option_branch_prices')->updateOrInsert(
                        ['menu_item_option_id' => $match->id, 'branch_id' => $loser->branch_id],
                        ['price' => $loserOption->price, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }

        return $map;
    }

    /**
     * A loser's recipe was written global (branch_id null) but was only ever
     * that branch's, because its option ids belonged to that branch alone. On
     * the survivor's option it becomes what it always meant: a per-branch
     * override. If the survivor already has a recipe for that exact pair the
     * loser's is dropped — unique(menu_item_option_id, branch_id).
     *
     * @param  array<int, int>  $optionMap
     */
    private function repointRecipes(array $optionMap, int $loserBranchId): int
    {
        $moved = 0;

        foreach ($optionMap as $from => $to) {
            $recipes = DB::table('inventory_recipes')
                ->where('menu_item_option_id', $from)
                ->whereNull('deleted_at')
                ->get();

            foreach ($recipes as $recipe) {
                $taken = DB::table('inventory_recipes')
                    ->where('menu_item_option_id', $to)
                    ->where('branch_id', $loserBranchId)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($taken) {
                    $this->warnings[] = "Recipe #{$recipe->id} dropped: option #{$to} already has one for branch {$loserBranchId}.";

                    if (! $this->dry) {
                        DB::table('inventory_recipes')->where('id', $recipe->id)->update(['deleted_at' => now()]);
                    }

                    continue;
                }

                $moved++;

                if (! $this->dry) {
                    DB::table('inventory_recipes')->where('id', $recipe->id)->update([
                        'menu_item_option_id' => $to,
                        'branch_id' => $loserBranchId,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        return $moved;
    }

    /**
     * Any override the loser's option already carried follows it across.
     *
     * @param  array<int, int>  $optionMap
     */
    private function repointOptionPrices(array $optionMap, int $loserBranchId): void
    {
        if ($this->dry) {
            return;
        }

        foreach ($optionMap as $from => $to) {
            $existing = DB::table('menu_item_option_branch_prices')
                ->where('menu_item_option_id', $from)
                ->get();

            foreach ($existing as $row) {
                DB::table('menu_item_option_branch_prices')->updateOrInsert(
                    ['menu_item_option_id' => $to, 'branch_id' => $row->branch_id],
                    ['price' => $row->price, 'is_available' => $row->is_available, 'updated_at' => now(), 'created_at' => now()],
                );
                DB::table('menu_item_option_branch_prices')->where('id', $row->id)->delete();
            }
        }
    }

    /**
     * Order and cart lines. Historic orders keep their own snapshot and price,
     * so this changes what the line points at, never what it says.
     *
     * @param  array<int, int>  $optionMap
     */
    private function repointLineItems(int $loserId, int $survivorId, array $optionMap): void
    {
        if ($this->dry) {
            return;
        }

        foreach (['order_items', 'cart_items'] as $table) {
            DB::table($table)->where('menu_item_id', $loserId)->update(['menu_item_id' => $survivorId]);

            foreach ($optionMap as $from => $to) {
                DB::table($table)->where('menu_item_option_id', $from)->update(['menu_item_option_id' => $to]);
            }
        }
    }

    /**
     * unique(customer_id, menu_item_id) — one customer who rated the same dish
     * at two branches keeps their most recent score.
     */
    private function repointRatings(int $loserId, int $survivorId): int
    {
        $rows = DB::table('menu_item_ratings')->where('menu_item_id', $loserId)->get();
        $merged = 0;

        foreach ($rows as $row) {
            $existing = DB::table('menu_item_ratings')
                ->where('menu_item_id', $survivorId)
                ->where('customer_id', $row->customer_id)
                ->first();

            $merged++;

            if ($this->dry) {
                continue;
            }

            if (! $existing) {
                DB::table('menu_item_ratings')->where('id', $row->id)->update(['menu_item_id' => $survivorId]);

                continue;
            }

            if ($row->created_at > $existing->created_at) {
                DB::table('menu_item_ratings')->where('id', $existing->id)->update(['rating' => $row->rating]);
            }

            DB::table('menu_item_ratings')->where('id', $row->id)->delete();
        }

        return $merged;
    }

    /**
     * A two-column pivot: move the rows that do not collide, drop the ones that
     * would duplicate a pair the survivor already has.
     */
    private function repointPivot(string $table, string $otherColumn, int $loserId, int $survivorId): void
    {
        if ($this->dry) {
            return;
        }

        $rows = DB::table($table)->where('menu_item_id', $loserId)->get();

        foreach ($rows as $row) {
            $collides = DB::table($table)
                ->where('menu_item_id', $survivorId)
                ->where($otherColumn, $row->{$otherColumn})
                ->exists();

            $collides
                ? DB::table($table)->where('id', $row->id)->delete()
                : DB::table($table)->where('id', $row->id)->update(['menu_item_id' => $survivorId]);
        }
    }

    /**
     * The survivor now carries every branch's ratings, so its average has to be
     * recomputed from the merged set.
     */
    private function recomputeRating(int $menuItemId): void
    {
        $stats = DB::table('menu_item_ratings')
            ->where('menu_item_id', $menuItemId)
            ->whereNull('deleted_at')
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
            ->first();

        DB::table('menu_items')->where('id', $menuItemId)->update([
            'rating' => $stats->total > 0 ? round((float) $stats->avg_rating, 1) : null,
            'rating_count' => (int) $stats->total,
            'updated_at' => now(),
        ]);
    }

    /**
     * A dish that only ever existed at one branch still needs its pivot row, or
     * unifying the reads would make it vanish from that branch's menu.
     */
    private function backfillPivotForSingletons(): void
    {
        $missing = MenuItem::query()
            ->whereNotNull('branch_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('menu_item_branches')
                ->whereColumn('menu_item_branches.menu_item_id', 'menu_items.id'))
            ->get(['id', 'branch_id', 'is_available']);

        if ($missing->isEmpty()) {
            return;
        }

        $this->line("Adding {$missing->count()} pivot row(s) for dishes that exist at one branch only.");

        if ($this->dry) {
            return;
        }

        foreach ($missing as $item) {
            $this->serveAt($item->id, (int) $item->branch_id, (bool) $item->is_available);
        }
    }
}
