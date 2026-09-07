<?php

namespace App\Support\Menu;

use Illuminate\Support\Str;

/**
 * What a line of an order is called on a receipt.
 *
 * A menu item and the option under it are written for somebody standing at the
 * menu board, where the item name is the question and the option is the answer:
 *
 *     Assorted Fried Rice / Jollof / Noodles + Full Chicken + Kɔkɔɔ
 *       Fried Rice     Jollof     Noodles
 *
 * On a receipt there is no board and no question. Joining the two gives
 * "Assorted Fried Rice / Jollof / Noodles + Full Chicken + Kɔkɔɔ, Fried Rice",
 * which offers the reader two dishes they did not order and then names the one
 * they did as an afterthought. `menu_item_options.display_name` exists to hold
 * the sentence a person would actually say: "Assorted Fried Rice + Full Chicken
 * + Kɔkɔɔ".
 *
 * The names cannot be derived. Collapsing the slash group to the chosen member
 * gets "Fried Rice" right and "Jollof" wrong, because "Assorted" in the first
 * member distributes over all three and no string operation can know that. So
 * they are written down, once, here.
 *
 * Keyed on `Str::slug` of the item's **name**, not on its stored slug: the
 * admin create path and `menu:unify` both append a timestamp to the slug, so
 * `drumsticks-1775317539318` and `drumsticks` are the same dish.
 */
class ReceiptNames
{
    /**
     * The names, item slug → option key → receipt name.
     *
     * @return array<string, array<string, string>>
     */
    public static function map(): array
    {
        return [
            'jollof' => [
                'plain' => 'Plain Jollof',
                'assorted' => 'Assorted Jollof',
                'seafood' => 'Seafood Jollof',
            ],
            'fried-rice' => [
                'plain' => 'Plain Fried Rice',
                'assorted' => 'Assorted Fried Rice',
                'seafood' => 'Seafood Fried Rice',
            ],
            'noodles' => [
                'assorted' => 'Assorted Noodles',
                'seafood' => 'Seafood Noodles',
            ],
            'banku' => [
                'grilled-tilapia' => 'Banku with Grilled Tilapia',
            ],
            'drumsticks' => [
                'special-crunch-5-pieces' => 'Special Crunch Drumsticks (5 pcs)',
                'special-crunch-10-pieces' => 'Special Crunch Drumsticks (10 pcs)',
                'juicy-fried-5-pieces' => 'Juicy Fried Drumsticks (5 pcs)',
                'juicy-fried-10-pieces' => 'Juicy Fried Drumsticks (10 pcs)',
            ],
            'rotisserie-grilled' => [
                'full' => 'Full Rotisserie Grilled Chicken',
                'half-cut' => 'Half Cut Rotisserie Grilled Chicken',
            ],
            'fried-rice-jollof-3-pieces-of-chicken' => [
                'fried-rice' => 'Fried Rice + 3 pieces of Chicken',
                'jollof' => 'Jollof + 3 pieces of Chicken',
            ],
            'assorted-fried-rice-jollof-noodles-3-pieces-of-chicken' => [
                'fried-rice' => 'Assorted Fried Rice + 3 pieces of Chicken',
                'jollof' => 'Assorted Jollof + 3 pieces of Chicken',
                'noodles' => 'Assorted Noodles + 3 pieces of Chicken',
            ],
            'fried-rice-jollof-7-pieces-of-chicken-kk' => [
                'fried-rice' => 'Fried Rice + 7 pieces of Chicken + Kɔkɔɔ',
                'jollof' => 'Jollof + 7 pieces of Chicken + Kɔkɔɔ',
            ],
            'assorted-fried-rice-jollof-noodles-7-pieces-of-chicken-kk' => [
                'fried-rice' => 'Assorted Fried Rice + 7 pieces of Chicken + Kɔkɔɔ',
                'jollof' => 'Assorted Jollof + 7 pieces of Chicken + Kɔkɔɔ',
                'noodles' => 'Assorted Noodles + 7 pieces of Chicken + Kɔkɔɔ',
            ],
            'assorted-fried-rice-jollof-noodles-full-chicken-kk' => [
                'fried-rice' => 'Assorted Fried Rice + Full Chicken + Kɔkɔɔ',
                'jollof' => 'Assorted Jollof + Full Chicken + Kɔkɔɔ',
                'noodles' => 'Assorted Noodles + Full Chicken + Kɔkɔɔ',
            ],
            'cedi-wraps' => [
                'chicken' => 'Chicken Cedi Wrap',
                'beef' => 'Beef Cedi Wrap',
                'mix' => 'Mix Cedi Wrap',
            ],
        ];
    }

    /**
     * Names the four combos went by before the 2026-09-06 rename.
     *
     * The dishes were renamed on production from "3 Drums" and "7 Drums" to "3
     * pieces of Chicken" and "7 pieces of Chicken", because the combos were
     * never made with drumsticks. Beta and any database that has not taken the
     * rename still hold the old names, and a receipt name is about the dish, not
     * about which side of that rename its row happens to be on.
     *
     * @return array<string, string> old item slug → current item slug
     */
    public static function renamedItems(): array
    {
        return [
            'fried-rice-jollof-3-drums' => 'fried-rice-jollof-3-pieces-of-chicken',
            'assorted-fried-rice-jollof-noodles-3-drums' => 'assorted-fried-rice-jollof-noodles-3-pieces-of-chicken',
            'fried-rice-jollof-7-drums-kk' => 'fried-rice-jollof-7-pieces-of-chicken-kk',
            'assorted-fried-rice-jollof-noodles-7-drums-kk' => 'assorted-fried-rice-jollof-noodles-7-pieces-of-chicken-kk',
        ];
    }

    /** Every receipt name written down for one dish, or an empty array. */
    public static function forItemName(string $itemName): array
    {
        $slug = Str::slug($itemName);
        $slug = self::renamedItems()[$slug] ?? $slug;

        return self::map()[$slug] ?? [];
    }

    /** The receipt name for one option, or null if none has been written. */
    public static function forOption(string $itemName, string $optionKey): ?string
    {
        return self::forItemName($itemName)[$optionKey] ?? null;
    }
}
