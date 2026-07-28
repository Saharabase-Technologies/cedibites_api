<?php

namespace App\Services\SmartCategories;

use App\Enums\SmartCategory;
use App\Models\MenuItem;
use App\Models\SmartCategorySetting;
use App\Services\SmartCategories\Resolvers\NewArrivalsResolver;
use App\Services\SmartCategories\Resolvers\OrderAgainResolver;
use App\Services\SmartCategories\Resolvers\PopularResolver;
use App\Services\SmartCategories\Resolvers\TimeBasedResolver;
use App\Services\SmartCategories\Resolvers\TopRatedResolver;
use App\Services\SmartCategories\Resolvers\TrendingResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates smart category resolution, caching, and visibility.
 *
 * Smart categories are code-defined virtual categories whose items are
 * computed from order data, ratings, timestamps, or customer history.
 * Results are cached per branch and refreshed by a scheduled command.
 * Admin settings (enabled, item_limit, time windows) are respected.
 */
class SmartCategoryService
{
    private const CACHE_TTL_SECONDS = 6 * 60 * 60; // 6 hours

    /**
     * Get all smart categories that should be visible right now for a branch.
     *
     * Filters by admin settings (enabled), time-of-day visibility, and customer requirements.
     * Returns each category with its resolved item IDs, ordered by display_order.
     *
     * @return array<int, array{slug: string, name: string, icon: string, item_ids: int[]}>
     */
    public function getActiveForContext(int $branchId, ?int $customerId = null): array
    {
        $currentHour = (int) now()->format('G');
        $settings = $this->getSettings();
        $results = [];

        foreach ($settings as $setting) {
            if (! $setting->is_enabled) {
                continue;
            }

            $category = $setting->smartCategory();

            // Skip customer-only categories for guests
            if ($category->requiresCustomer() && $customerId === null) {
                continue;
            }

            // Skip time-based categories outside their visible window
            if (! $this->isVisibleAtHour($setting, $currentHour)) {
                continue;
            }

            $itemIds = $this->resolve($category, $branchId, $customerId, $setting->item_limit);

            // Only include categories that have items
            if ($itemIds->isEmpty()) {
                continue;
            }

            $results[] = [
                'slug' => $category->value,
                'name' => $category->label(),
                'icon' => $category->icon(),
                'item_ids' => $itemIds->values()->all(),
            ];
        }

        return $results;
    }

    /**
     * Resolve a single smart category for a branch.
     * Uses cache for non-personalized categories; computes live for personalized ones.
     *
     * @return Collection<int, int>
     */
    public function resolve(SmartCategory $category, int $branchId, ?int $customerId = null, ?int $limit = null): Collection
    {
        $limit ??= $this->getSettingFor($category)?->item_limit ?? $category->defaultLimit();

        // Personalized categories (OrderAgain) are computed live per customer
        if ($category->requiresCustomer()) {
            return $this->getResolver($category)
                ->resolve($branchId, $limit, $customerId);
        }

        $cacheKey = $this->cacheKey($category, $branchId);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($category, $branchId, $limit) {
            return $this->getResolver($category)
                ->resolve($branchId, $limit);
        });
    }

    /**
     * Warm the cache for all enabled, non-personalized smart categories for a branch.
     * Called by the scheduled command.
     */
    public function warmCacheForBranch(int $branchId): void
    {
        $settings = $this->getSettings();

        foreach ($settings as $setting) {
            if (! $setting->is_enabled) {
                continue;
            }

            $category = $setting->smartCategory();

            if ($category->requiresCustomer()) {
                continue;
            }

            $cacheKey = $this->cacheKey($category, $branchId);
            $itemIds = $this->getResolver($category)
                ->resolve($branchId, $setting->item_limit);

            Cache::put($cacheKey, $itemIds, self::CACHE_TTL_SECONDS);
        }
    }

    /**
     * Invalidate all smart category caches for a branch.
     * Useful after bulk menu changes or settings changes.
     */
    public function invalidateBranch(int $branchId): void
    {
        foreach (SmartCategory::cases() as $category) {
            if ($category->requiresCustomer()) {
                continue;
            }

            Cache::forget($this->cacheKey($category, $branchId));
        }
    }

    /**
     * Hydrate item IDs into full MenuItem models (with relationships).
     * Used when the API needs to return full item data instead of just IDs.
     *
     * @param  Collection<int, int>  $itemIds
     * @return Collection<int, MenuItem>
     */
    public function hydrateItems(Collection $itemIds, int $branchId): Collection
    {
        if ($itemIds->isEmpty()) {
            return collect();
        }

        return MenuItem::query()
            ->with([
                'category',
                'options' => fn ($q) => $q->orderBy('display_order'),
                'options.media',
                'options.branchPrices' => fn ($q) => $q->where('branch_id', $branchId),
                'tags',
            ])
            ->whereIn('id', $itemIds)
            ->where('is_available', true)
            ->get()
            ->sortBy(fn (MenuItem $item) => $itemIds->search($item->id));
    }

    public function getResolver(SmartCategory $category): SmartCategoryResolver
    {
        return match ($category) {
            SmartCategory::MostPopular => new PopularResolver,
            SmartCategory::Trending => new TrendingResolver,
            SmartCategory::TopRated => new TopRatedResolver,
            SmartCategory::NewArrivals => new NewArrivalsResolver,
            SmartCategory::BreakfastFavorites => new TimeBasedResolver($category),
            SmartCategory::LunchPicks => new TimeBasedResolver($category),
            SmartCategory::DinnerFavorites => new TimeBasedResolver($category),
            SmartCategory::LateNightBites => new TimeBasedResolver($category),
            SmartCategory::OrderAgain => new OrderAgainResolver,
        };
    }

    /**
     * Check visibility at a given hour, using custom time window if set.
     */
    private function isVisibleAtHour(SmartCategorySetting $setting, int $hour): bool
    {
        $category = $setting->smartCategory();

        // Non-time-based categories with no custom window are always visible
        if (! $category->isTimeBased() && ! $setting->hasCustomTimeWindow()) {
            return true;
        }

        // Use custom time window from settings if set, otherwise fall back to enum defaults
        $start = $setting->visible_hour_start;
        $end = $setting->visible_hour_end;

        if ($start === null || $end === null) {
            return $category->isVisibleAtHour($hour);
        }

        // Handle overnight windows (e.g. 21 → 3)
        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }

    /** Get the setting row for a specific smart category. */
    private function getSettingFor(SmartCategory $category): ?SmartCategorySetting
    {
        return $this->getSettings()->firstWhere('slug', $category->value);
    }

    /**
     * Load all settings ordered by display_order.
     * Cached in memory for the request lifecycle.
     *
     * @return Collection<int, SmartCategorySetting>
     */
    private function getSettings(): Collection
    {
        return once(fn () => SmartCategorySetting::query()
            ->orderBy('display_order')
            ->get());
    }

    private function cacheKey(SmartCategory $category, int $branchId): string
    {
        return "smart_category:{$category->value}:branch:{$branchId}";
    }
}
