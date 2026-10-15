<?php

namespace App\Console\Commands;

use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Services\Menu\MenuDrafts;
use App\Services\Menu\MenuValues;
use Illuminate\Console\Command;

/**
 * Loads the October 2026 price list as drafts. Nothing goes live: the owner
 * reviews the New prices page and presses the button.
 *
 * The list is the printed menu as the owner marked it up by hand on
 * 2026-10-15. Dishes not on that menu keep today's price. See
 * docs/MENU_PRICES_BUILD.md, section 5, in the frontend repo.
 *
 * Every line names the dish, the option and the value it expects today, and
 * is skipped with a reason when the server holds something else. A guess
 * about which dish was meant is how a price lands on the wrong one.
 *
 * Safe to run twice. A draft that exists is overwritten with the same value,
 * and a new option already waiting is not added again.
 */
class MenuStageOctoberPrices extends Command
{
    protected $signature = 'menu:stage-october-prices
                            {--dry-run : Show what would be staged and write nothing}';

    protected $description = 'Load the October 2026 price list as drafts on the New prices page';

    private const COMBO_3 = 'Fried Rice / Jollof Rice / Noodles + 3 pieces of Chicken';

    private const ASSORTED_3 = 'Assorted Fried Rice / Jollof Rice / Noodles + 3 pieces of Chicken';

    private const ASSORTED_FULL = 'Assorted Fried Rice / Jollof Rice / Noodles + Full Chicken + Kɔkɔɔ';

    // Two spaces, as it is on prod. The rename below fixes it.
    private const BANKU_SMALL = 'Banku With  Tilapia';

    private const BANKU_XL = 'Banku With Grilled Tilapia';

    private const CRUNCH = 'Drumsticks (Special Crunch)';

    private const JUICY = 'Drumsticks (Juicy Fried)';

    /** [dish, option key, today, new] */
    private const PRICES = [
        ['Jollof Rice', 'assorted', 85, 90],
        ['Fried Rice', 'assorted', 80, 90],
        ['Noodles', 'assorted', 80, 90],
        ['Jollof Rice', 'seafood', 105, 120],
        ['Fried Rice', 'seafood', 100, 120],
        ['Noodles', 'seafood', 100, 120],
        [self::BANKU_SMALL, 'grilled-tilapia', 100, 110],
        ['Rotisserie Grilled Chicken', 'full', 170, 200],
        ['Rotisserie Grilled Chicken', 'half-cut', 90, 110],
        [self::COMBO_3, 'fried-rice', 90, 100],
        [self::COMBO_3, 'jollof-rice', 95, 100],
        [self::ASSORTED_3, 'assorted-fried-rice', 110, 120],
        [self::ASSORTED_3, 'assorted-noodles', 110, 120],
        [self::ASSORTED_3, 'assorted-jollof-rice', 115, 120],
        [self::ASSORTED_FULL, 'assorted-fried-rice', 250, 260],
        [self::ASSORTED_FULL, 'assorted-jollof-rice', 255, 260],
        ['Cedi Wraps', 'chicken', 60, 70],
        ['Cedi Wraps', 'beef', 60, 70],
        ['Cedi Wraps', 'mix', 70, 85],
    ];

    /** [dish, option key, field, today, new] */
    private const OPTION_TEXT = [
        [self::BANKU_SMALL, 'grilled-tilapia', 'option_label', 'Grilled Tilapia', 'Small'],
        [self::BANKU_SMALL, 'grilled-tilapia', 'display_name', 'Banku With Grilled Tilapia', 'Banku With Grilled Tilapia (Small)'],
        [self::CRUNCH, '5-pieces', 'option_label', '5 Pieces', '5 Drumsticks'],
        [self::CRUNCH, '10-pieces', 'option_label', '10 Pieces', '10 Drumsticks'],
        [self::JUICY, '5-pieces', 'option_label', '5 Pieces', '5 Drumsticks'],
        [self::JUICY, '10-pieces', 'option_label', '10 Pieces', '10 Drumsticks'],
    ];

    /** [dish, label, receipt name, price] */
    private const NEW_OPTIONS = [
        [self::BANKU_SMALL, 'XL', 'Banku With Grilled Tilapia (XL)', 140],
        [self::CRUNCH, '7 Wings', '7 Pieces of Special Crunch Wings', 65],
        [self::CRUNCH, '15 Wings', '15 Pieces of Special Crunch Wings', 110],
        [self::JUICY, '7 Wings', '7 Pieces of Juicy Fried Wings', 65],
        [self::JUICY, '15 Wings', '15 Pieces of Juicy Fried Wings', 110],
    ];

    /** [dish today, dish after] */
    private const RENAMES = [
        [self::BANKU_SMALL, 'Banku With Grilled Tilapia'],
        [self::CRUNCH, 'Drumsticks and Wings (Special Crunch)'],
        [self::JUICY, 'Drumsticks and Wings (Juicy Fried)'],
    ];

    /** [dish, the one option's price today]. Banku becomes one dish in two sizes. */
    private const OFF_SALE = [
        [self::BANKU_XL, 140],
    ];

    private bool $dry = false;

    /** @var list<array{string, string, string, string}> */
    private array $rows = [];

    public function handle(MenuDrafts $drafts): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('DRY RUN. Nothing will be written.');
        }

        // Every dish looked up before anything is written, so a rename staged
        // here can never change which dish a later line finds.
        $dishes = [];
        foreach ([...self::PRICES, ...self::OPTION_TEXT, ...self::NEW_OPTIONS, ...self::RENAMES, ...self::OFF_SALE] as $line) {
            $dishes[$line[0]] ??= $this->dish($line[0]);
        }

        foreach (self::PRICES as [$name, $key, $today, $new]) {
            $option = $this->findOption($dishes[$name], $key);
            $line = $option ? MenuValues::lineName($dishes[$name], $option) : "{$name}, {$key}";

            if ($option === null) {
                $this->row($line, (string) $today, (string) $new, $this->missing($dishes[$name], $name, $key));

                continue;
            }

            $this->stage($line, $option, 'price', $today, $new, fn () => $drafts->set($dishes[$name], $option, 'price', $new, null));
        }

        foreach (self::OPTION_TEXT as [$name, $key, $field, $today, $new]) {
            $option = $this->findOption($dishes[$name], $key);

            if ($option === null) {
                $this->row("{$name}, {$key}", $today, $new, $this->missing($dishes[$name], $name, $key));

                continue;
            }

            $this->stage(MenuValues::lineName($dishes[$name], $option), $option, $field, $today, $new,
                fn () => $drafts->set($dishes[$name], $option, $field, $new, null));
        }

        foreach (self::RENAMES as [$name, $new]) {
            $dish = $dishes[$name];

            if ($dish === null) {
                $this->row($name, $name, $new, 'Skipped: no single dish has this name.');

                continue;
            }

            $this->stage($name, $dish, 'name', $name, $new, fn () => $drafts->set($dish, null, 'name', $new, null));
        }

        foreach (self::NEW_OPTIONS as [$name, $label, $receipt, $price]) {
            $dish = $dishes[$name];

            if ($dish === null) {
                $this->row($receipt, 'new', (string) $price, 'Skipped: no single dish has this name.');

                continue;
            }

            if ($this->alreadyHas($dish, $label)) {
                $this->row($receipt, 'new', (string) $price, 'Already there or already waiting.');

                continue;
            }

            if (! $this->dry) {
                $drafts->addOption($dish, $label, $receipt, (float) $price, null);
            }

            $this->row($receipt, 'new', (string) $price, 'Staged');
        }

        foreach (self::OFF_SALE as [$name, $price]) {
            $dish = $dishes[$name];
            $only = $dish?->options->count() === 1 ? $dish->options->first() : null;

            if ($dish === null || $only === null || round((float) $only->price, 2) !== round((float) $price, 2)) {
                $this->row($name, 'on sale', 'off sale', "Skipped: expected one dish named this, at {$price}.");

                continue;
            }

            if (! $this->dry) {
                $drafts->set($dish, null, 'off_sale', true, null);
            }

            $this->row($name, 'on sale', 'off sale', 'Staged');
        }

        $this->table(['Line', 'Today', 'New', 'Result'], $this->rows);

        $skipped = collect($this->rows)->filter(fn ($r) => str_starts_with($r[3], 'Skipped'))->count();
        $this->newLine();
        $this->info(($this->dry ? 'Would stage ' : 'Staged ').(count($this->rows) - $skipped).' lines. Skipped '.$skipped.'.');
        $this->line('Nothing is live. The owner reviews the New prices page and presses Put new prices live.');

        return self::SUCCESS;
    }

    private function stage(string $line, MenuItem|MenuItemOption $target, string $field, mixed $today, mixed $new, \Closure $write): void
    {
        $current = MenuValues::current($target, $field);
        $expected = MenuValues::normalise($field, $today);

        // The rename of the small Banku fixes a double space, so its name is
        // compared exactly as it is stored.
        if ($field === 'name') {
            $expected = (string) $today;
        }

        if ($current !== $expected) {
            $this->row($line, (string) $today, (string) $new, "Skipped: the server has {$current}.");

            return;
        }

        if (! $this->dry) {
            $write();
        }

        $this->row($line, (string) $today, (string) $new, 'Staged');
    }

    private function dish(string $name): ?MenuItem
    {
        $found = MenuItem::query()->with('options')->where('name', $name)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    private function findOption(?MenuItem $dish, string $key): ?MenuItemOption
    {
        return $dish?->options->firstWhere('option_key', $key);
    }

    private function missing(?MenuItem $dish, string $name, string $key): string
    {
        return $dish === null
            ? 'Skipped: no single dish is named "'.$name.'".'
            : "Skipped: the dish has no option {$key}.";
    }

    private function alreadyHas(MenuItem $dish, string $label): bool
    {
        if ($dish->options->contains(fn (MenuItemOption $o) => $o->option_label === $label)) {
            return true;
        }

        return MenuDraft::query()
            ->where('menu_item_id', $dish->id)
            ->where('field', MenuDraft::NEW_OPTION)
            ->get()
            ->contains(fn (MenuDraft $d) => ($d->value['option_label'] ?? null) === $label);
    }

    private function row(string $line, string $today, string $new, string $result): void
    {
        $this->rows[] = [$line, $today, $new, $result];
    }
}
