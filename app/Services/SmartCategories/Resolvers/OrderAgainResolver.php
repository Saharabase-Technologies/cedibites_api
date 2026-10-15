<?php

namespace App\Services\SmartCategories\Resolvers;

use App\Services\SmartCategories\SmartCategoryResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves "Order Again" — items this customer has ordered before.
 *
 * Ranked by frequency (most re-ordered first). Requires a logged-in customer.
 */
class OrderAgainResolver implements SmartCategoryResolver
{
    public function resolve(int $branchId, int $limit, ?int $customerId = null): Collection
    {
        if ($customerId === null) {
            return collect();
        }

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
            ->where('orders.customer_id', $customerId)
            ->where('menu_items.is_available', true)
            ->whereNull('menu_items.deleted_at')
            ->select('menu_items.id')
            ->selectRaw('SUM(order_items.quantity) as total_units')
            ->groupBy('menu_items.id')
            ->orderByDesc('total_units')
            ->limit($limit)
            ->pluck('menu_items.id');
    }
}
