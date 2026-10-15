<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value a publish changed, with what it was before. Undo reads these.
 *
 * `field` is a MenuDraft field, except that a new option is logged as
 * `created` and taking a dish off sale as `is_available`.
 */
class MenuPublicationChange extends Model
{
    protected $fillable = [
        'menu_publication_id',
        'menu_item_id',
        'menu_item_option_id',
        'field',
        'old_value',
        'new_value',
        'line_name',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'json',
            'new_value' => 'json',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(MenuPublication::class, 'menu_publication_id');
    }
}
