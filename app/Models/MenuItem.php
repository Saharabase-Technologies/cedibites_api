<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MenuItem extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['name', 'category_id', 'is_available'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'branch_id',
        'category_id',
        'name',
        'slug',
        'description',
        'is_available',
        'rating',
        'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'rating' => 'float',
            'rating_count' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The branches that serve this dish.
     *
     * Replaces `branch_id` (see docs/BRANCH_ISOLATION_PLAN.md, Phase 3). Every
     * branch is the same institution behind a different till, so a dish is one
     * row and this says where it is sold. `is_available` on the pivot is the
     * branch manager's "sold out today"; `menu_items.is_available` takes the
     * dish off every branch at once.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'menu_item_branches')
            ->withPivot('is_available')
            ->withTimestamps();
    }

    /**
     * Dishes served at a branch.
     *
     * Reads correctly both before and after `menu:unify` runs. Once the merge
     * has populated the pivot every item has a row and the first clause decides
     * everything; until then, an item with no pivot rows still answers to its
     * legacy branch_id. Without that fallback, deploying the migration before
     * running the command would empty every menu in the business.
     */
    public function scopeServedAt(Builder $query, int|string $branchId): Builder
    {
        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId))
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->where('branch_id', $branchId)
                    ->whereDoesntHave('branches'));
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuItemOption::class)->orderBy('display_order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MenuTag::class, 'menu_item_menu_tag')->withTimestamps();
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
