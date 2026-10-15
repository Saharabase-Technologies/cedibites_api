<?php

namespace App\Services\Menu;

use App\Enums\MenuPublicationStatus;
use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\MenuPublication;
use App\Models\MenuPublicationChange;

/**
 * Everything the New prices page shows, in one response, so it paints without
 * a request per dish: every dish and option with today's values and what is
 * waiting, the scheduled publish, and recent history.
 */
class MenuPriceBoard
{
    private const HISTORY = 10;

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $drafts = MenuDraft::query()->orderBy('id')->get();

        $dishes = MenuItem::query()
            ->with([
                'category:id,name,display_order',
                'options' => fn ($q) => $q->orderBy('display_order')->orderBy('id'),
            ])
            ->get()
            ->sortBy(fn (MenuItem $item) => sprintf(
                '%06d|%s|%010d',
                $item->category?->display_order ?? 999999,
                $item->category?->name ?? '~',
                $item->id,
            ))
            ->values()
            ->map(fn (MenuItem $item) => $this->dish($item, $drafts))
            ->all();

        $scheduled = MenuPublication::query()
            ->with('scheduler:id,name')
            ->where('status', MenuPublicationStatus::Scheduled->value)
            ->latest('id')
            ->first();

        return [
            'dishes' => $dishes,
            'waiting_count' => $drafts->count(),
            'scheduled' => $scheduled === null ? null : [
                'id' => $scheduled->id,
                'publish_at' => $scheduled->publish_at?->toIso8601String(),
                'scheduled_by' => $scheduled->scheduler?->name,
            ],
            'history' => $this->history(),
            'server_now' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MenuDraft>  $drafts
     * @return array<string, mixed>
     */
    private function dish(MenuItem $item, $drafts): array
    {
        $mine = $drafts->where('menu_item_id', $item->id);

        return [
            'id' => $item->id,
            'name' => $item->name,
            'category' => $item->category?->name,
            'is_available' => (bool) $item->is_available,
            'drafts' => $this->draftsFor($mine->whereNull('menu_item_option_id')->whereIn('field', MenuDraft::DISH_FIELDS)),
            'options' => $item->options
                ->map(fn (MenuItemOption $option) => [
                    'id' => $option->id,
                    'option_key' => $option->option_key,
                    'option_label' => $option->option_label,
                    'display_name' => $option->display_name,
                    'price' => (float) $option->price,
                    'drafts' => $this->draftsFor($mine->where('menu_item_option_id', $option->id)),
                ])
                ->values()
                ->all(),
            'new_options' => $mine
                ->where('field', MenuDraft::NEW_OPTION)
                ->map(fn (MenuDraft $d) => [
                    'draft_id' => $d->id,
                    'option_label' => $d->value['option_label'] ?? '',
                    'display_name' => $d->value['display_name'] ?? '',
                    'price' => (float) ($d->value['price'] ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Keyed by field: {price: {id, value}}.
     *
     * @param  \Illuminate\Support\Collection<int, MenuDraft>  $drafts
     */
    private function draftsFor($drafts): object
    {
        $out = [];

        foreach ($drafts as $draft) {
            $out[$draft->field] = ['id' => $draft->id, 'value' => $draft->value];
        }

        // An object, never a list, so an empty set reads {} in json.
        return (object) $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $publications = MenuPublication::query()
            ->with(['changes', 'scheduler:id,name', 'publisher:id,name', 'undoer:id,name'])
            ->where('status', '!=', MenuPublicationStatus::Scheduled->value)
            ->latest('id')
            ->limit(self::HISTORY)
            ->get();

        $latestLive = (int) MenuPublication::query()
            ->where('status', MenuPublicationStatus::Published->value)
            ->max('id');

        return $publications
            ->map(fn (MenuPublication $p) => [
                'id' => $p->id,
                'status' => $p->status->value,
                'publish_at' => $p->publish_at?->toIso8601String(),
                'published_at' => $p->published_at?->toIso8601String(),
                'undone_at' => $p->undone_at?->toIso8601String(),
                'scheduled_by' => $p->scheduler?->name,
                'published_by' => $p->publisher?->name,
                'undone_by' => $p->undoer?->name,
                'change_count' => $p->change_count,
                'note' => $p->note,
                'can_undo' => $p->status === MenuPublicationStatus::Published && $p->id === $latestLive,
                'changes' => $p->changes
                    ->sortBy('id')
                    ->map(fn (MenuPublicationChange $c) => [
                        'field' => $c->field,
                        'line_name' => $c->line_name,
                        'old_value' => $c->old_value,
                        'new_value' => $c->new_value,
                    ])
                    ->values()
                    ->all(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])
            ->all();
    }
}
