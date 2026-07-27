<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Branch extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['name', 'address', 'is_active', 'extended_staff_access', 'extended_order_access'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'area',
        'address',
        'phone',
        'email',
        'latitude',
        'longitude',
        'is_active',
        'extended_staff_access',
        'extended_order_access',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_active' => 'boolean',
            'extended_staff_access' => 'boolean',
            'extended_order_access' => 'boolean',
        ];
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_branch')->withTimestamps();
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(MenuCategory::class);
    }

    /**
     * Legacy: dishes whose `branch_id` is this branch.
     *
     * Superseded by servedMenuItems(). Kept while menu_items.branch_id still
     * exists so items the merge has not reached are not lost — see
     * docs/BRANCH_ISOLATION_PLAN.md, Phase 3.
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * Dishes this branch serves. One dish, many branches.
     */
    public function servedMenuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'menu_item_branches')
            ->withPivot('is_available')
            ->withTimestamps();
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The IMS location(s) holding this branch's stock. Normally exactly one
     * satellite; a branch with none cannot be seen in the inventory portal and
     * its sales fall back to debiting the mother kitchen, so
     * BranchProvisioningService creates one whenever a branch is created.
     */
    public function inventoryLocations(): HasMany
    {
        return $this->hasMany(\App\Models\Inventory\Location::class);
    }

    /**
     * Get the manager(s) for this branch.
     * Returns employees with 'manager' role assigned to this branch.
     */
    public function managers(): BelongsToMany
    {
        return $this->employees()
            ->whereHas('user.roles', function ($query) {
                $query->where('name', 'manager');
            })
            ->where('status', 'active');
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(BranchOperatingHour::class);
    }

    public function deliverySettings(): HasMany
    {
        return $this->hasMany(BranchDeliverySetting::class);
    }

    public function activeDeliverySetting(): ?BranchDeliverySetting
    {
        return $this->deliverySettings()
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now());
            })
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    public function orderTypes(): HasMany
    {
        return $this->hasMany(BranchOrderType::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(BranchPaymentMethod::class);
    }

    /**
     * Get the primary manager for this branch.
     * Returns the first active manager, or null if none exists.
     */
    public function manager(): ?Employee
    {
        return $this->managers()->first();
    }

    /**
     * Determine if the branch is currently open based on schedule and manual overrides.
     */
    public function isCurrentlyOpen(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = strtolower(now()->format('l')); // monday, tuesday, etc.

        $todayHours = $this->operatingHours()
            ->where('day_of_week', $today)
            ->first();

        if (! $todayHours) {
            return false;
        }

        return $todayHours->isCurrentlyOpen();
    }

    /**
     * Get the open status as a string (open, closed, busy).
     */
    public function getOpenStatus(): string
    {
        if (! $this->is_active) {
            return 'closed';
        }

        return $this->isCurrentlyOpen() ? 'open' : 'closed';
    }

    /**
     * Whether staff systems (POS, KDS, Order Manager) are accessible.
     * True when branch is open normally OR extended_staff_access is enabled.
     */
    public function isStaffAccessAllowed(): bool
    {
        return $this->isCurrentlyOpen() || $this->extended_staff_access;
    }

    /**
     * Whether new orders can be placed via staff systems (POS) outside operating hours.
     * Requires both extended_staff_access AND extended_order_access.
     */
    public function isExtendedOrderAllowed(): bool
    {
        return $this->extended_staff_access && $this->extended_order_access;
    }
}
