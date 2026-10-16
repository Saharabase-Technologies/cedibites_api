<?php

namespace App\Services\Bolt;

use App\Models\MenuItem;
use App\Models\MenuItemOption;

/**
 * Everything the menu's Bolt page shows, in one response: the commission
 * settings, and every dish with our price beside its Bolt price and promo.
 *
 * Dishes come in the order the New prices page uses, so the two pages read
 * alike.
 */
class BoltBoard
{
    public function __construct(private BoltSettings $settings) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
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
            ->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                'is_available' => (bool) $item->is_available,
                'options' => $item->options
                    ->map(fn (MenuItemOption $option) => [
                        'id' => $option->id,
                        'option_key' => $option->option_key,
                        'option_label' => $option->option_label,
                        'display_name' => $option->display_name,
                        'price' => (float) $option->price,
                        'bolt_price' => $option->bolt_price !== null ? (float) $option->bolt_price : null,
                        'bolt_promo_percent' => $option->bolt_promo_percent !== null ? (float) $option->bolt_promo_percent : null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();

        return [
            'settings' => $this->settings->toArray(),
            'dishes' => $dishes,
            'on_bolt_count' => MenuItemOption::query()->whereNotNull('bolt_price')->count(),
        ];
    }
}
