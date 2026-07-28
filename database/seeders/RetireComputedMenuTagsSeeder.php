<?php

namespace Database\Seeders;

use App\Models\MenuTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Takes `popular` and `new` off already-seeded environments.
 *
 * MenuTagSeeder only ever adds, so dropping the two entries from it does
 * nothing to production — the same reason ManagerScopeCleanupSeeder exists for
 * the permission ceiling. This is the revoke half.
 *
 * Both are now computed: SmartCategory::MostPopular from 30 days of order
 * frequency, SmartCategory::NewArrivals from timestamps. Leaving the manual
 * versions in place means two answers to one question, and the hand-set one
 * goes stale the day after it is set.
 *
 * Deactivate *and* detach. Deactivating alone would hide the tags from the
 * editor while leaving the badges on every item they were ever applied to —
 * a label nobody can see the source of and nobody can remove.
 *
 * The tag rows themselves survive: activity-log entries and any historical
 * reference still resolve, and re-activating is one flag if this turns out to
 * be wrong.
 */
class RetireComputedMenuTagsSeeder extends Seeder
{
    private const RETIRED = ['popular', 'new'];

    public function run(): void
    {
        $tags = MenuTag::query()->whereIn('slug', self::RETIRED)->get();

        if ($tags->isEmpty()) {
            $this->command?->info('No computed tags to retire.');

            return;
        }

        // The detach is the one thing here that cannot be undone: the tag rows
        // survive, but *which items carried them* does not. Write that down
        // before destroying it, so changing our mind is a restore rather than a
        // re-tagging exercise across the whole menu.
        $record = [];

        foreach ($tags as $tag) {
            $itemIds = $tag->menuItems()->pluck('menu_items.id')->all();
            $record[$tag->slug] = $itemIds;
        }

        $path = 'retired-menu-tags-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($record, JSON_PRETTY_PRINT));
        $this->command?->info('Wrote the previous assignments to storage/app/'.$path);

        foreach ($tags as $tag) {
            $count = count($record[$tag->slug]);

            $tag->menuItems()->detach();
            $tag->update([
                'is_active' => false,
                'rule_description' => 'Retired — now computed by Smart Categories.',
            ]);

            $this->command?->info(
                "Retired '{$tag->slug}' — detached from {$count} item(s), marked inactive."
            );
        }
    }
}
