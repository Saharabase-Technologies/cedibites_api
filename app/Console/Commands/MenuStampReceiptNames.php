<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Support\Menu\ReceiptNames;
use Illuminate\Console\Command;

/**
 * Fill in the receipt names the menu never got.
 *
 * `menu_item_options.display_name` is what a receipt, a ticket, the tracking
 * page and the analytics tables call a line. It is nullable, the admin menu
 * screen exposes it as an optional field, and on the live menu almost every
 * option is null — only the handful somebody typed by hand have one.
 *
 * With it null, every screen falls back to `option_label`, which is a pill on
 * the menu board and not a sentence, and the customer reads:
 *
 *     Assorted Fried Rice / Jollof / Noodles + Full Chicken + Kɔkɔɔ, Fried Rice
 *
 * when what they bought was an Assorted Fried Rice + Full Chicken + Kɔkɔɔ.
 *
 * MenuSeeder has carried the correct names all along, but it only writes them
 * on a fresh seed of a fresh branch, so no existing menu ever received them.
 * This applies the same list to a menu that is already live.
 *
 * Only blanks by default. A name a manager typed themselves is theirs, and the
 * list here is not more authoritative than the person who runs the branch.
 *
 *     php artisan menu:stamp-receipt-names --dry-run
 *     php artisan menu:stamp-receipt-names
 */
class MenuStampReceiptNames extends Command
{
    protected $signature = 'menu:stamp-receipt-names
                            {--dry-run : Report what would change and write nothing}
                            {--overwrite : Also replace names that are already set}';

    protected $description = 'Fill in menu_item_options.display_name, the name a receipt prints, from the written list';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $overwrite = (bool) $this->option('overwrite');

        $items = MenuItem::query()
            ->with(['options' => fn ($q) => $q->withTrashed()])
            ->get();

        $written = 0;
        $matched = 0;
        $leftAlone = 0;
        $unlisted = [];
        $rows = [];

        foreach ($items as $item) {
            $names = ReceiptNames::forItemName((string) $item->name);

            foreach ($item->options as $option) {
                $wanted = $names[$option->option_key] ?? null;

                if ($wanted === null) {
                    // Nothing written down for this one. Worth naming out loud
                    // rather than counting as a success.
                    if (blank($option->display_name)) {
                        $unlisted[] = "{$item->name} → {$option->option_label}";
                    }

                    continue;
                }

                $current = (string) ($option->display_name ?? '');

                if ($current === $wanted) {
                    $matched++;

                    continue;
                }

                if ($current !== '' && ! $overwrite) {
                    // Somebody typed their own. Left alone, but reported, so a
                    // deliberate difference is visible rather than silent.
                    $rows[] = [$item->name, $option->option_key, $current, 'kept, differs from the list'];
                    $leftAlone++;

                    continue;
                }

                $rows[] = [$item->name, $option->option_key, $wanted, $current === '' ? 'filled in' : 'replaced'];

                if (! $dry) {
                    $option->forceFill(['display_name' => $wanted])->saveQuietly();
                }

                $written++;
            }
        }

        if ($rows !== []) {
            $this->table(['Dish', 'Option', 'Receipt name', 'What happened'], $rows);
        }

        if ($unlisted !== []) {
            $this->newLine();
            $this->warn(count($unlisted).' option(s) have no receipt name and none is written down for them:');
            foreach (array_slice($unlisted, 0, 40) as $line) {
                $this->line('  '.$line);
            }
            if (count($unlisted) > 40) {
                $this->line('  … and '.(count($unlisted) - 40).' more');
            }
            $this->line('  Add them in app/Support/Menu/ReceiptNames.php, or on the admin menu screen.');
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d receipt name(s). %d already matched the list. %d option(s) across %d dish(es) checked.',
            $dry ? 'Would write' : 'Wrote',
            $written,
            $matched,
            MenuItemOption::query()->count(),
            $items->count(),
        ));

        if ($leftAlone > 0) {
            $this->comment(sprintf(
                '%d option(s) already carry a different name and were left alone. Pass --overwrite to replace them.',
                $leftAlone,
            ));
        }

        if ($dry) {
            $this->comment('Dry run. Nothing was written.');
        }

        return self::SUCCESS;
    }
}
