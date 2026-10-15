<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One menu change waiting to go live. Nothing that sells reads this table.
 *
 * `value` holds, by field:
 *   price          a number, on an option
 *   option_label   the pill the customer picks, on an option
 *   display_name   the receipt name, on an option
 *   name           the dish's name
 *   off_sale       true: the dish comes off sale everywhere
 *   new_option     {option_key, option_label, display_name, price, display_order}
 */
class MenuDraft extends Model
{
    public const OPTION_FIELDS = ['price', 'option_label', 'display_name'];

    public const DISH_FIELDS = ['name', 'off_sale'];

    public const NEW_OPTION = 'new_option';

    protected $fillable = [
        'menu_item_id',
        'menu_item_option_id',
        'field',
        'value',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuItemOption::class, 'menu_item_option_id')->withTrashed();
    }
}
