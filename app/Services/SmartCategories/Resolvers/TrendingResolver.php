<?php

namespace App\Services\SmartCategories\Resolvers;

use App\Services\SmartCategories\SmartCategoryResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves "Trending Now" — items with the biggest increase in order velocity.
 *
 * Compares order count in the last 7 days vs the previous 7 days.
 * Items with the largest positive delta are "trending".
 */
class TrendingResolver implements SmartCategoryResolver
{
    public function resolve(int $branchId, int $limit, ?int $customerId = null): Collection
    {
        $now = now();
        $currentStart = $now->copy()->subDays(7);
        $previousStart = $now->copy()->subDays(14);

        $currentPeriod = $this->getOrderCounts($branchId, $currentStart, $now);
        $previousPeriod = $this->getOrderCounts($branchId, $previousStart, $currentStart);

        // Calculate velocity increase (current - previous), rank by delta
        return $currentPeriod
            ->map(function (int $currentCount, int $itemId) use ($previousPeriod) {
                $previousCount = $previousPeriod->get($itemId, 0);

                return $previousCount > 0
                    ? ($currentCount - $previousCount) / $previousCount
                    : ($currentCount > 0 ? 1.0 : 0.0);
            })
            ->filter(fn (float $delta) => $delta > 0)
            ->sortDesc()
            ->take($limit)
            ->keys();
    }

    /**
     * @return Collection<int, int> Keyed by menu_item_id → order count
     */
    private function getOrderCounts(int $branchId, $from, $to): Collection
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereExists(function ($q) {
                $q->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereIn('payments.payment_status', ['completed', 'no_charge']);
            })
            ->where('orders.status', '!=', 'cancelled')
            ->where(fn ($q) => \App\Models\OrderItem::leaveOutCancelledRounds($q))
            ->where('orders.branch_id', $branchId)
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('menu_items.is_available', true)
            ->whereNull('menu_items.deleted_at')
            ->select('menu_items.id')
            ->selectRaw('SUM(order_items.quantity) as total_units')
            ->groupBy('menu_items.id')
            ->pluck('total_units', 'menu_items.id')
            ->map(fn ($v) => (int) $v);
    }
}
