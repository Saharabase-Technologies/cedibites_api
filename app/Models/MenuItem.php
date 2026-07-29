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

    /**
     * Dishes a branch is actually selling right now.
     *
     * `servedAt` answers "is this on that branch's menu", which is the question
     * the manager's own availability screen asks — it has to list the sold-out
     * ones in order to put them back. It is the wrong question for a till.
     *
     * Nothing asked this one until now, so a manager marking a dish sold out
     * changed the pivot and nothing else: the POS kept offering it, because its
     * only availability filter was `menu_items.is_available` — the company-wide
     * flag. The toggle was decorative on every screen that sells.
     *
     * Kept separate rather than folded into `servedAt` precisely so the
     * manager's screen keeps seeing everything.
     */
    public function scopeOnSaleAt(Builder $query, int|string $branchId): Builder
    {
        return $query
            ->servedAt($branchId)
            ->where('menu_items.is_available', true)
            // Absent pivot row = the legacy branch_id fallback above, which has
            // no flag to read. No verdict never means refuse — the same rule the
            // stock gate follows — or deploying this ahead of the data would
            // empty every menu that has not been merged yet.
            ->whereDoesntHave('branches', fn (Builder $b) => $b
                ->where('branches.id', $branchId)
                ->where('menu_item_branches.is_available', false));
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
