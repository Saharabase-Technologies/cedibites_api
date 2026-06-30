<?php

namespace App\Models\Inventory;

use App\Models\Branch;
use App\Models\MenuItemOption;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    use SoftDeletes;

    protected $table = 'inventory_recipes';

    protected $fillable = [
        'menu_item_option_id',
        'branch_id',
        'is_default',
        'status',
        'version',
        'yield_qty',
        'locked_by_id',
        'locked_at',
    ];

    public function menuItemOption(): BelongsTo
    {
        return $this->belongsTo(MenuItemOption::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class, 'recipe_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'version' => 'integer',
            'yield_qty' => 'decimal:4',
            'locked_at' => 'datetime',
        ];
    }
}
