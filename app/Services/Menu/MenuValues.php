<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use App\Models\MenuItemOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One way to read, compare and name menu values, so a draft, a publish and an
 * undo all agree on whether two values are the same.
 *
 * Prices are compared as numbers rounded to the pesewa. The column comes back
 * as "90.00" and a draft may hold 90, and those are the same price.
 */
class MenuValues
{
    public static function current(Model $target, string $field): float|string|null
    {
        $value = $target->getAttribute($field);

        if ($field === 'price') {
            return round((float) $value, 2);
        }

        return $value === null ? null : (string) $value;
    }

    public static function normalise(string $field, mixed $value): float|string|null
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'price') {
            return round((float) $value, 2);
        }

        // One space between words. "Banku With  Tilapia" is how a double space
        // got onto the live menu.
        return preg_replace('/\s+/u', ' ', trim((string) $value));
    }

    /**
     * What a line is called in the history: the receipt name when there is
     * one, the dish for a dish with no real option, else the option and its
     * dish.
     */
    public static function lineName(MenuItem $item, MenuItemOption $option): string
    {
        $display = trim((string) $option->display_name);

        if ($display !== '') {
            return $display;
        }

        if ($option->option_key === 'standard') {
            return $item->name;
        }

        return "{$option->option_label} ({$item->name})";
    }

    /**
     * An option key not yet used on this dish. The unique index covers
     * deleted options too, so they count.
     */
    public static function freeOptionKey(MenuItem $item, string $wanted, array $alsoTaken = []): string
    {
        $base = Str::slug($wanted) ?: 'option';

        $taken = MenuItemOption::withTrashed()
            ->where('menu_item_id', $item->id)
            ->pluck('option_key')
            ->merge($alsoTaken)
            ->all();

        $key = $base;
        $n = 2;

        while (in_array($key, $taken, true)) {
            $key = "{$base}-{$n}";
            $n++;
        }

        return $key;
    }
}
