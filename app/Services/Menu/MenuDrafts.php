<?php

namespace App\Services\Menu;

use App\Models\MenuDraft;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\User;

/**
 * Writing the waiting changes. Nothing here touches the live menu.
 */
class MenuDrafts
{
    /**
     * Set one waiting change, or clear it.
     *
     * A value equal to today's is cleared rather than kept. Otherwise the
     * page would count a change that changes nothing, and the review would
     * show 90 becoming 90.
     */
    public function set(MenuItem $item, ?MenuItemOption $option, string $field, mixed $value, ?User $by): void
    {
        $existing = MenuDraft::query()
            ->where('menu_item_id', $item->id)
            ->where('menu_item_option_id', $option?->id)
            ->where('field', $field)
            ->first();

        if ($this->isNoChange($item, $option, $field, $value)) {
            $existing?->delete();

            return;
        }

        $stored = $field === 'off_sale' ? true : MenuValues::normalise($field, $value);

        if ($existing !== null) {
            $existing->update(['value' => $stored, 'updated_by' => $by?->id]);

            return;
        }

        MenuDraft::create([
            'menu_item_id' => $item->id,
            'menu_item_option_id' => $option?->id,
            'field' => $field,
            'value' => $stored,
            'created_by' => $by?->id,
            'updated_by' => $by?->id,
        ]);
    }

    /**
     * An option that will be created when the changes go live.
     */
    public function addOption(MenuItem $item, string $label, string $receiptName, float $price, ?User $by): MenuDraft
    {
        $waiting = MenuDraft::query()
            ->where('menu_item_id', $item->id)
            ->where('field', MenuDraft::NEW_OPTION)
            ->get();

        $taken = $waiting->map(fn (MenuDraft $d) => (string) ($d->value['option_key'] ?? ''))->all();

        $nextOrder = max(
            (int) $item->options()->max('display_order'),
            (int) $waiting->max(fn (MenuDraft $d) => (int) ($d->value['display_order'] ?? 0)),
        ) + 1;

        return MenuDraft::create([
            'menu_item_id' => $item->id,
            'menu_item_option_id' => null,
            'field' => MenuDraft::NEW_OPTION,
            'value' => [
                'option_key' => MenuValues::freeOptionKey($item, $label, $taken),
                'option_label' => MenuValues::normalise('option_label', $label),
                'display_name' => MenuValues::normalise('display_name', $receiptName),
                'price' => MenuValues::normalise('price', $price),
                'display_order' => $nextOrder,
            ],
            'created_by' => $by?->id,
            'updated_by' => $by?->id,
        ]);
    }

    private function isNoChange(MenuItem $item, ?MenuItemOption $option, string $field, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if ($field === 'off_sale') {
            return ! filter_var($value, FILTER_VALIDATE_BOOLEAN) || ! $item->is_available;
        }

        $target = $option ?? $item;

        return MenuValues::current($target, $field) === MenuValues::normalise($field, $value);
    }
}
