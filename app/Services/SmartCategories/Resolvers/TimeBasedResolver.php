<?php

namespace App\Services\SmartCategories\Resolvers;

use App\Enums\SmartCategory;
use App\Services\SmartCategories\SmartCategoryResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves time-of-day categories (Breakfast, Lunch, Dinner, Late Night).
 *
 * Ranks items by order volume during the configured hour window over the
 * last 30 days. The exact hours come from SmartCategory::orderHours().
 */
class TimeBasedResolver implements SmartCategoryResolver
{
    public function __construct(private SmartCategory $smartCategory) {}

    public function resolve(int $branchId, int $limit, ?int $customerId = null): Collection
    {
        $hours = $this->smartCategory->orderHours();
        if ($hours === null) {
            return collect();
        }

        $query = DB::table('order_items')
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
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->where('menu_items.is_available', true)
            ->whereNull('menu_items.deleted_at');

        // Apply hour filter — handles overnight windows (e.g. 21 → 4)
        if ($hours['start'] > $hours['end']) {
            $query->where(function ($q) use ($hours) {
                $q->whereRaw('EXTRACT(HOUR FROM orders.created_at) >= ?', [$hours['start']])
                    ->orWhereRaw('EXTRACT(HOUR FROM orders.created_at) < ?', [$hours['end']]);
            });
        } else {
            $query->whereRaw('EXTRACT(HOUR FROM orders.created_at) >= ?', [$hours['start']])
                ->whereRaw('EXTRACT(HOUR FROM orders.created_at) < ?', [$hours['end']]);
        }

        return $query
            ->select('menu_items.id')
            ->selectRaw('SUM(order_items.quantity) as total_units')
            ->groupBy('menu_items.id')
            ->orderByDesc('total_units')
            ->limit($limit)
            ->pluck('menu_items.id');
    }
}
