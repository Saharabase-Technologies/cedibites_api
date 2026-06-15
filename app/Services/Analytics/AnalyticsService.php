<?php

namespace App\Services\Analytics;

use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unified analytics engine — the single source of truth for all metrics.
 *
 * Every controller, dashboard, report, and API endpoint MUST use this service.
 * No inline analytics computation is permitted outside this class.
 *
 * Architecture: AnalyticsQueryBuilder → AnalyticsService → Controllers → Frontend
 */
class AnalyticsService
{
    public function __construct(
        protected AnalyticsQueryBuilder $queryBuilder,
    ) {}

    // ─── A. SALES METRICS ───────────────────────────────────────────

    /**
     * @return array{gross_revenue: float, total_orders: int, completed_orders: int, cancelled_orders: int, cancelled_revenue: float, no_charge_count: int, no_charge_amount: float, average_order_value: float, sales_by_day: \Illuminate\Support\Collection, sales_by_type: \Illuminate\Support\Collection, avg_items_per_order: float}
     */
    public function getSalesMetrics(array $filters = []): array
    {
        $grossRevenue = $this->queryBuilder->computeRevenue($filters);
        $revenueOrderCount = $this->queryBuilder->computeRevenueOrderCount($filters);
        $totalOrders = $this->queryBuilder->computePlacedOrderCount($filters);

        $completedOrders = $this->queryBuilder->completedOrders($filters)->count();

        $cancelledQuery = $this->queryBuilder->cancelledOrders($filters);
        $cancelledOrders = (clone $cancelledQuery)->count();
        $cancelledRevenue = round((float) (clone $cancelledQuery)->sum('total_amount'), 2);

        $noChargeQuery = $this->queryBuilder->noChargeOrders($filters);
        $noChargeCount = (clone $noChargeQuery)->count();
        $noChargeAmount = round((float) (clone $noChargeQuery)->sum('total_amount'), 2);

        $averageOrderValue = $revenueOrderCount > 0
            ? round($grossRevenue / $revenueOrderCount, 2)
            : 0;

        // Sales by day
        $salesByDay = (clone $this->queryBuilder->revenueOrders($filters))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('COUNT(*) as orders')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Sales by order type
        $salesByType = (clone $this->queryBuilder->revenueOrders($filters))
            ->select('order_type', DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as orders'))
            ->groupBy('order_type')
            ->get();

        // Average items per order + supporting stats
        $revenueOrderIds = (clone $this->queryBuilder->revenueOrders($filters))->select('id');
        $totalItems = \App\Models\OrderItem::whereIn('order_id', $revenueOrderIds)->sum('quantity');
        $avgItemsPerOrder = $revenueOrderCount > 0 ? round($totalItems / $revenueOrderCount, 1) : 0;

        // Items-per-order distribution
        $itemCounts = \App\Models\OrderItem::whereIn('order_id', $revenueOrderIds)
            ->select('order_id', DB::raw('SUM(quantity) as item_count'))
            ->groupBy('order_id')
            ->get();
        $singleItemOrders = $itemCounts->where('item_count', 1)->count();
        $multiItemOrders = $itemCounts->where('item_count', '>', 1)->count();
        $maxItemsInOrder = (int) ($itemCounts->max('item_count') ?? 0);
        $singleItemPct = $revenueOrderCount > 0 ? round(($singleItemOrders / $revenueOrderCount) * 100) : 0;

        return [
            'total_sales' => $grossRevenue,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'cancelled_revenue' => $cancelledRevenue,
            'no_charge_count' => $noChargeCount,
            'no_charge_amount' => $noChargeAmount,
            'average_order_value' => $averageOrderValue,
            'sales_by_day' => $salesByDay,
            'sales_by_type' => $salesByType,
            'avg_items_per_order' => $avgItemsPerOrder,
            'single_item_orders_pct' => $singleItemPct,
            'multi_item_orders' => $multiItemOrders,
            'max_items_in_order' => $maxItemsInOrder,
        ];
    }

    // ─── B. ORDER METRICS ───────────────────────────────────────────

    /**
     * @return array{orders_by_status: \Illuminate\Support\Collection, orders_by_hour: \Illuminate\Support\Collection, active_orders: int, total_orders: int, average_prep_time: float|null}
     */
    public function getOrderMetrics(array $filters = []): array
    {
        $placedQuery = $this->queryBuilder->placedOrders($filters);

        // Orders by status
        $ordersByStatus = (clone $placedQuery)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        // Orders by hour — PostgreSQL compatible
        $driver = DB::connection()->getDriverName();
        $hourExpression = $driver === 'pgsql'
            ? 'EXTRACT(HOUR FROM created_at)::integer'
            : 'HOUR(created_at)';

        $ordersByHour = (clone $placedQuery)
            ->select(DB::raw("{$hourExpression} as hour"), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $activeOrders = $this->queryBuilder->activeOrders($filters)->count();
        $totalOrders = $this->queryBuilder->computePlacedOrderCount($filters);

        // Average prep time from OrderStatusHistory
        $averagePrepTime = $this->computeAveragePrepTime($filters);

        return [
            'orders_by_status' => $ordersByStatus,
            'orders_by_hour' => $ordersByHour,
            'active_orders' => $activeOrders,
            'total_orders' => $totalOrders,
            'average_prep_time' => $averagePrepTime,
        ];
    }

    // ─── C. CUSTOMER METRICS ────────────────────────────────────────

    /**
     * @return array{total_customers: int, new_customers_in_period: int, top_customers_by_orders: \Illuminate\Support\Collection, top_customers_by_spending: \Illuminate\Support\Collection}
     */
    public function getCustomerMetrics(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $customerQuery = Customer::query();
        if ($dateFrom) {
            $customerQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $customerQuery->whereDate('created_at', '<=', $dateTo);
        }

        $totalCustomers = (clone $customerQuery)->count();

        // New customers = first placed order falls in date range
        $newCustomers = 0;
        if ($dateFrom && $dateTo) {
            $newCustomers = Customer::whereHas(
                'orders',
                fn ($q) => $q->paymentConfirmed()
                    ->whereDate('created_at', '>=', $dateFrom)
                    ->whereDate('created_at', '<=', $dateTo)
            )->whereDoesntHave(
                'orders',
                fn ($q) => $q->paymentConfirmed()
                    ->whereDate('created_at', '<', $dateFrom)
            )->count();
        }

        // Top customers by fulfilled orders — only completed/delivered count
        $revenueSubquery = $this->queryBuilder->revenueOrders($filters)->select('id');
        $revenueOrderIds = (clone $revenueSubquery)->pluck('id');

        $fulfilledOrderIds = Order::whereIn('id', $revenueOrderIds)
            ->whereIn('status', ['completed', 'delivered'])
            ->pluck('id');

        $topByOrders = Customer::query()
            ->with(['user', 'orders' => fn ($q) => $q->latest()->limit(1)])
            ->whereHas('orders', fn ($q) => $q->whereIn('orders.id', $fulfilledOrderIds))
            ->withCount(['orders as placed_order_count' => fn ($q) => $q->whereIn('orders.id', $fulfilledOrderIds)])
            ->addSelect([
                'total_spend' => Order::selectRaw('SUM(total_amount)')
                    ->whereColumn('customer_id', 'customers.id')
                    ->whereIn('id', $revenueOrderIds),
            ])
            ->orderByDesc('placed_order_count')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->user?->name ?? $c->orders->first()?->contact_name ?? 'Guest',
                'orders_count' => (int) $c->placed_order_count,
                'total_spend' => round((float) ($c->total_spend ?? 0), 2),
            ]);

        // Top customers by spending
        $topBySpending = Customer::query()
            ->with(['user', 'orders' => fn ($q) => $q->latest()->limit(1)])
            ->whereHas('orders', fn ($q) => $q->whereIn('orders.id', $revenueOrderIds))
            ->addSelect([
                'total_spend' => Order::selectRaw('SUM(total_amount)')
                    ->whereColumn('customer_id', 'customers.id')
                    ->whereIn('id', $revenueOrderIds),
            ])
            ->orderByDesc('total_spend')
            ->limit(10)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->user?->name ?? $c->orders->first()?->contact_name ?? 'Guest',
                'orders_count' => (int) ($c->placed_order_count ?? $c->orders_count ?? 0),
                'total_spend' => round((float) ($c->total_spend ?? 0), 2),
            ]);

        // New customers in last 30 days (always computed, not date-dependent)
        $thirtyDaysAgo = now()->subDays(30)->toDateString();
        $newCustomers30Days = Customer::whereHas(
            'orders',
            fn ($q) => $q->paymentConfirmed()
                ->whereDate('created_at', '>=', $thirtyDaysAgo)
        )->whereDoesntHave(
            'orders',
            fn ($q) => $q->paymentConfirmed()
                ->whereDate('created_at', '<', $thirtyDaysAgo)
        )->count();

        return [
            'total_customers' => $totalCustomers,
            'new_customers_30_days' => $newCustomers30Days,
            'new_customers_in_period' => $newCustomers,
            'top_customers_by_orders' => $topByOrders,
            'top_customers_by_spending' => $topBySpending,
        ];
    }

    // ─── D. ITEM & MENU METRICS ─────────────────────────────────────

    public function getTopItemsMetrics(array $filters = [], int $limit = 10): array
    {
        $query = $this->queryBuilder->orderItems($filters);

        $items = $query
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_options', 'order_items.menu_item_option_id', '=', 'menu_item_options.id')
            ->select(
                'menu_items.name',
                DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label) as size_label'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM(order_items.subtotal) as revenue')
            )
            ->groupBy('menu_items.name', DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label)'))
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        // Compute trend vs previous period
        $previousItems = $this->getPreviousPeriodItems($filters, $limit);

        return $items->map(function ($item) use ($previousItems) {
            $key = $item->name.'|'.($item->size_label ?? '');
            $prevRevenue = $previousItems[$key] ?? 0;
            $trend = $prevRevenue > 0 ? round((($item->revenue - $prevRevenue) / $prevRevenue) * 100) : 0;

            return [
                'name' => $item->name,
                'size_label' => $item->size_label,
                'units' => (int) $item->units,
                'rev' => round($item->revenue, 2),
                'trend' => $trend,
            ];
        })->toArray();
    }

    public function getBottomItemsMetrics(array $filters = [], int $limit = 5): array
    {
        $query = $this->queryBuilder->orderItems($filters);

        return $query
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_options', 'order_items.menu_item_option_id', '=', 'menu_item_options.id')
            ->select(
                'menu_items.name',
                DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label) as size_label'),
                DB::raw('SUM(order_items.quantity) as units'),
                DB::raw('SUM(order_items.subtotal) as revenue')
            )
            ->groupBy('menu_items.name', DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label)'))
            ->orderBy('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->name,
                'size_label' => $item->size_label,
                'units' => (int) $item->units,
                'rev' => round($item->revenue, 2),
            ])
            ->toArray();
    }

    public function getCategoryRevenueMetrics(array $filters = []): array
    {
        $query = $this->queryBuilder->orderItems($filters);

        $categories = $query
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->join('menu_categories', 'menu_items.category_id', '=', 'menu_categories.id')
            ->select(
                'menu_categories.name as cat',
                DB::raw('SUM(order_items.subtotal) as rev')
            )
            ->groupBy('menu_categories.name')
            ->get();

        $totalRevenue = $categories->sum('rev');

        return $categories->map(fn ($c) => [
            'cat' => $c->cat,
            'rev' => round($c->rev, 2),
            'pct' => $totalRevenue > 0 ? round(($c->rev / $totalRevenue) * 100) : 0,
        ])->sortByDesc('rev')->values()->toArray();
    }

    // ─── E. BRANCH PERFORMANCE METRICS ──────────────────────────────

    public function getBranchMetrics(array $filters = []): array
    {
        $query = $this->queryBuilder->placedOrders($filters);

        $branches = (clone $query)
            ->select(
                'branch_id',
                DB::raw("SUM(CASE
                    WHEN status != 'cancelled'
                         AND EXISTS (SELECT 1 FROM payments WHERE payments.order_id = orders.id AND payments.payment_status = 'completed')
                    THEN total_amount ELSE 0 END) as revenue"),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw("AVG(CASE
                    WHEN status != 'cancelled'
                         AND EXISTS (SELECT 1 FROM payments WHERE payments.order_id = orders.id AND payments.payment_status = 'completed')
                    THEN total_amount ELSE NULL END) as avg_value"),
                DB::raw("COUNT(CASE WHEN status IN ('completed', 'delivered') THEN 1 END) as completed_orders"),
                DB::raw("COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders")
            )
            ->groupBy('branch_id')
            ->get();

        // Pre-load branch names to avoid N+1
        $branchNames = \App\Models\Branch::whereIn('id', $branches->pluck('branch_id'))
            ->pluck('name', 'id');

        return $branches->map(function ($b) use ($branchNames) {
            $fulfilmentRate = $b->total_orders > 0
                ? round(($b->completed_orders / $b->total_orders) * 100)
                : 0;

            $cancellationRate = $b->total_orders > 0
                ? round(($b->cancelled_orders / $b->total_orders) * 100)
                : 0;

            return [
                'id' => (int) $b->branch_id,
                'name' => $branchNames[$b->branch_id] ?? 'Unknown',
                'rev' => round($b->revenue ?? 0, 2),
                'orders' => $b->completed_orders,
                'avg' => round($b->avg_value ?? 0, 2),
                'fulfilment' => $fulfilmentRate,
                'cancelled' => $cancellationRate,
            ];
        })->sortByDesc('rev')->values()->toArray();
    }

    // ─── F. STAFF SALES METRICS ─────────────────────────────────────

    /**
     * Per-staff sales breakdown by payment method for a given date and branch.
     *
     * Uses DB-level conditional aggregation on payments.amount (not orders.total_amount)
     * to avoid double-counting when an order has multiple payment records.
     *
     * Payment grouping:
     *  - MoMo:        mobile_money
     *  - Cash:        cash + cash_on_delivery (merged)
     *  - Manual MoMo: manual_momo (past-order recordings)
     *  - Card:        card
     *  - No Charge:   payment_status = 'no_charge'
     */
    public function getStaffSalesMetrics(array $filters = []): array
    {
        // Build from scratch with explicit table prefixes to avoid
        // "ambiguous column" errors when joining payments/employees/users.
        $query = Order::query()
            ->join('payments', function ($join) {
                $join->on('payments.order_id', '=', 'orders.id')
                    ->whereIn('payments.payment_status', ['completed', 'no_charge']);
            })
            ->leftJoin('employees', 'orders.assigned_employee_id', '=', 'employees.id')
            ->leftJoin('users', 'employees.user_id', '=', 'users.id')
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at');

        // Apply filters with explicit orders. prefix
        $this->queryBuilder->applyFilters($query, $filters, 'orders');

        $rows = $query
            ->select(
                DB::raw('COALESCE(orders.assigned_employee_id, 0) as employee_id'),
                DB::raw("COALESCE(users.name, 'Unassigned') as staff_name"),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                // MoMo (uses payments.amount to avoid double-count)
                DB::raw("SUM(CASE WHEN payments.payment_method = 'mobile_money' AND payments.payment_status = 'completed' THEN payments.amount ELSE 0 END) as momo_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN payments.payment_method = 'mobile_money' AND payments.payment_status = 'completed' THEN orders.id END) as momo_count"),
                // Cash (merged: cash + cash_on_delivery)
                DB::raw("SUM(CASE WHEN payments.payment_method IN ('cash', 'cash_on_delivery') AND payments.payment_status = 'completed' THEN payments.amount ELSE 0 END) as cash_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN payments.payment_method IN ('cash', 'cash_on_delivery') AND payments.payment_status = 'completed' THEN orders.id END) as cash_count"),
                // Manual MoMo (past-order recordings)
                DB::raw("SUM(CASE WHEN payments.payment_method = 'manual_momo' AND payments.payment_status = 'completed' THEN payments.amount ELSE 0 END) as manual_momo_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN payments.payment_method = 'manual_momo' AND payments.payment_status = 'completed' THEN orders.id END) as manual_momo_count"),
                // Card
                DB::raw("SUM(CASE WHEN payments.payment_method = 'card' AND payments.payment_status = 'completed' THEN payments.amount ELSE 0 END) as card_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN payments.payment_method = 'card' AND payments.payment_status = 'completed' THEN orders.id END) as card_count"),
                // No charge
                DB::raw("SUM(CASE WHEN payments.payment_status = 'no_charge' THEN payments.amount ELSE 0 END) as no_charge_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN payments.payment_status = 'no_charge' THEN orders.id END) as no_charge_count"),
            )
            ->groupBy(DB::raw('COALESCE(orders.assigned_employee_id, 0)'), DB::raw("COALESCE(users.name, 'Unassigned')"))
            ->orderByDesc(DB::raw("SUM(CASE WHEN payments.payment_status = 'completed' THEN payments.amount ELSE 0 END)"))
            ->get();

        return $rows->map(fn ($r) => [
            'employee_id' => $r->employee_id,
            'staff_name' => $r->staff_name,
            'total_orders' => (int) $r->total_orders,
            'momo_total' => round((float) $r->momo_total, 2),
            'momo_count' => (int) $r->momo_count,
            'cash_total' => round((float) $r->cash_total, 2),
            'cash_count' => (int) $r->cash_count,
            'manual_momo_total' => round((float) $r->manual_momo_total, 2),
            'manual_momo_count' => (int) $r->manual_momo_count,
            'card_total' => round((float) $r->card_total, 2),
            'card_count' => (int) $r->card_count,
            'no_charge_total' => round((float) $r->no_charge_total, 2),
            'no_charge_count' => (int) $r->no_charge_count,
            'total_revenue' => round(
                (float) $r->momo_total + (float) $r->cash_total + (float) $r->manual_momo_total + (float) $r->card_total,
                2,
            ),
        ])->toArray();
    }

    // ─── G. DELIVERY & PICKUP METRICS ───────────────────────────────

    public function getDeliveryPickupMetrics(array $filters = []): array
    {
        $query = $this->queryBuilder->revenueOrders($filters);

        // Also include no_charge for order type counts
        $allPlaced = $this->queryBuilder->placedOrders($filters)
            ->where('status', '!=', 'cancelled');

        $orderTypes = (clone $allPlaced)
            ->select('order_type', DB::raw('COUNT(*) as count'))
            ->groupBy('order_type')
            ->get();

        $totalOrders = $orderTypes->sum('count');

        // Build dynamic breakdown for all order types
        $types = $orderTypes->map(function ($row) use ($query, $totalOrders) {
            $revenue = (clone $query)->where('order_type', $row->order_type)->sum('total_amount');

            return [
                'type' => $row->order_type,
                'label' => ucfirst(str_replace('_', ' ', $row->order_type)),
                'pct' => $totalOrders > 0 ? round(($row->count / $totalOrders) * 100) : 0,
                'revenue' => round((float) $revenue, 2),
            ];
        })->sortByDesc('pct')->values()->toArray();

        // Keep legacy keys for backward compatibility
        $delivery = $orderTypes->where('order_type', 'delivery')->first();
        $pickup = $orderTypes->where('order_type', 'pickup')->first();

        return [
            'delivery_pct' => $totalOrders > 0 ? round((($delivery?->count ?? 0) / $totalOrders) * 100) : 0,
            'pickup_pct' => $totalOrders > 0 ? round((($pickup?->count ?? 0) / $totalOrders) * 100) : 0,
            'delivery_revenue' => round((float) ((clone $query)->where('order_type', 'delivery')->sum('total_amount')), 2),
            'pickup_revenue' => round((float) ((clone $query)->where('order_type', 'pickup')->sum('total_amount')), 2),
            'types' => $types,
        ];
    }

    // ─── H. PAYMENT METHOD METRICS ──────────────────────────────────

    public function getPaymentMethodMetrics(array $filters = []): array
    {
        $paidQuery = $this->queryBuilder->payments($filters, 'completed');

        $methods = (clone $paidQuery)
            ->select(
                'payments.payment_method',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(payments.amount) as amount'),
            )
            ->groupBy('payments.payment_method')
            ->get();

        // No-charge count
        $noChargeCount = $this->queryBuilder->noChargeOrders($filters)->count();

        $totalPayments = $methods->sum('count') + $noChargeCount;

        $result = $methods->map(function ($method) use ($totalPayments) {
            $label = match ($method->payment_method) {
                'mobile_money' => 'Mobile Money',
                'cash_on_delivery' => 'Cash on Delivery',
                'cash' => 'Cash',
                'card' => 'Card Payment',
                default => ucfirst(str_replace('_', ' ', $method->payment_method ?? 'Unknown'))
            };

            return [
                'label' => $label,
                'pct' => $totalPayments > 0 ? round(($method->count / $totalPayments) * 100) : 0,
                'amount' => round((float) ($method->amount ?? 0), 2),
                'count' => (int) $method->count,
            ];
        })->sortByDesc('pct')->values()->toArray();

        if ($noChargeCount > 0) {
            $result[] = [
                'label' => 'No Charge',
                'pct' => $totalPayments > 0 ? round(($noChargeCount / $totalPayments) * 100) : 0,
                'amount' => 0.0,
                'count' => $noChargeCount,
            ];
        }

        return $result;
    }

    // ─── I. SOURCE METRICS ──────────────────────────────────────────

    public function getSourceMetrics(array $filters = []): array
    {
        $query = $this->queryBuilder->placedOrders($filters)
            ->where('status', '!=', 'cancelled');

        $sources = (clone $query)
            ->select(
                'order_source',
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(total_amount) as avg_value'),
                DB::raw('SUM(total_amount) as total_revenue')
            )
            ->groupBy('order_source')
            ->get();

        $totalOrders = $sources->sum('count');

        return $sources->map(fn ($s) => [
            'name' => ucfirst(str_replace('_', ' ', $s->order_source ?? 'Unknown')),
            'count' => (int) $s->count,
            'pct' => $totalOrders > 0 ? round(($s->count / $totalOrders) * 100) : 0,
            'avgValue' => round($s->avg_value ?? 0, 2),
            'total_revenue' => round($s->total_revenue ?? 0, 2),
        ])->sortByDesc('count')->values()->toArray();
    }

    // ─── J. PAYMENT STATS (Transactions page) ──────────────────────

    public function getPaymentStats(array $filters = []): array
    {
        $query = Payment::query();

        if (isset($filters['branch_id'])) {
            $query->whereHas('order', fn ($q) => $q->where('branch_id', $filters['branch_id']));
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('payments.created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('payments.created_at', '<=', $filters['date_to']);
        }

        $rows = (clone $query)
            ->selectRaw('payment_status, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_status')
            ->get()
            ->keyBy('payment_status');

        // For no_charge, sum the order total_amount (payment amount is always 0)
        $noChargeOrderTotal = (clone $query)
            ->where('payments.payment_status', 'no_charge')
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->sum('orders.total_amount');

        $stat = fn (string $status) => [
            'count' => (int) ($rows[$status]->count ?? 0),
            'total' => (float) ($rows[$status]->total ?? 0),
        ];

        return [
            'completed' => $stat('completed'),
            'pending' => $stat('pending'),
            'refunded' => $stat('refunded'),
            'no_charge' => [
                'count' => (int) ($rows['no_charge']->count ?? 0),
                'total' => (float) $noChargeOrderTotal,
            ],
        ];
    }

    // ─── K. FULFILLMENT METRICS ─────────────────────────────────────

    public function getFulfillmentMetrics(array $filters = []): array
    {
        $orderIds = $this->queryBuilder->completedOrders($filters)->pluck('id');

        if ($orderIds->isEmpty()) {
            return [
                'avg_time_to_accept' => null,
                'avg_prep_time' => null,
                'avg_fulfillment_time' => null,
            ];
        }

        return [
            'avg_time_to_accept' => $this->computeTransitionTime($orderIds, 'received', ['accepted']),
            'avg_prep_time' => $this->computeTransitionTime($orderIds, 'preparing', ['ready', 'ready_for_pickup']),
            'avg_fulfillment_time' => $this->computeTransitionTime($orderIds, 'received', ['completed', 'delivered']),
        ];
    }

    // ─── L. PROMO METRICS ───────────────────────────────────────────

    public function getPromoMetrics(array $filters = []): array
    {
        $revenueQuery = $this->queryBuilder->revenueOrders($filters)
            ->whereNotNull('promo_id');

        $promosUsed = (clone $revenueQuery)
            ->select(
                'promo_id',
                'promo_name',
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('SUM(discount) as total_discount'),
                DB::raw('SUM(total_amount) as revenue_generated')
            )
            ->groupBy('promo_id', 'promo_name')
            ->get();

        return $promosUsed->map(fn ($p) => [
            'promo_id' => $p->promo_id,
            'promo_name' => $p->promo_name,
            'usage_count' => (int) $p->usage_count,
            'total_discount' => round($p->total_discount, 2),
            'revenue_generated' => round($p->revenue_generated, 2),
        ])->sortByDesc('usage_count')->values()->toArray();
    }

    // ─── L2. DISCOUNT USAGE METRICS ─────────────────────────────────

    public function getDiscountUsageMetrics(array $filters = []): array
    {
        $placedQuery = $this->queryBuilder->placedOrders($filters);

        $totalOrders = (clone $placedQuery)->count();

        $discountedQuery = (clone $placedQuery)->where('discount', '>', 0);
        $discountedCount = (clone $discountedQuery)->count();
        $totalDiscount = round((float) (clone $discountedQuery)->sum('discount'), 2);
        $avgDiscount = $discountedCount > 0 ? round($totalDiscount / $discountedCount, 2) : 0;

        return [
            'total_orders' => $totalOrders,
            'discounted_orders' => $discountedCount,
            'discount_rate' => $totalOrders > 0 ? round(($discountedCount / $totalOrders) * 100, 1) : 0,
            'total_discount_given' => $totalDiscount,
            'avg_discount_per_order' => $avgDiscount,
            'promos' => $this->getPromoMetrics($filters),
        ];
    }

    // ─── L3. CANCELLATION REASONS METRICS ─────────────────────────────

    public function getCancellationReasonsMetrics(array $filters = []): array
    {
        $cancelledQuery = $this->queryBuilder->cancelledOrders($filters);

        $totalCancelled = (clone $cancelledQuery)->count();

        $reasons = (clone $cancelledQuery)
            ->select(
                DB::raw("COALESCE(cancelled_reason, 'Unspecified') as reason"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy(DB::raw("COALESCE(cancelled_reason, 'Unspecified')"))
            ->orderByDesc('count')
            ->get();

        return [
            'total_cancelled' => $totalCancelled,
            'reasons' => $reasons->map(fn ($r) => [
                'reason' => $r->reason,
                'count' => (int) $r->count,
                'pct' => $totalCancelled > 0 ? round(($r->count / $totalCancelled) * 100, 1) : 0,
            ])->values()->toArray(),
        ];
    }

    // ─── M. CHECKOUT FUNNEL METRICS ─────────────────────────────────

    public function getFunnelMetrics(array $filters = []): array
    {
        $sessionQuery = CheckoutSession::query();

        if (isset($filters['date_from'])) {
            $sessionQuery->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $sessionQuery->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (isset($filters['branch_id'])) {
            $sessionQuery->whereHas('order', fn ($q) => $q->where('branch_id', $filters['branch_id']));
        }

        $totalSessions = (clone $sessionQuery)->count();
        $completedSessions = (clone $sessionQuery)->where('status', 'completed')->count();

        $conversionRate = $totalSessions > 0
            ? round(($completedSessions / $totalSessions) * 100, 1)
            : 0;

        return [
            'sessions_created' => $totalSessions,
            'sessions_completed' => $completedSessions,
            'conversion_rate' => $conversionRate,
        ];
    }

    // ─── N. DASHBOARD KPIs ──────────────────────────────────────────

    /**
     * Consolidated dashboard metrics — replaces inline AdminDashboardController logic.
     */
    public function getDashboardMetrics(array $filters = []): array
    {
        $today = now()->startOfDay()->toDateString();
        $todayFilters = array_merge($filters, ['date_from' => $today, 'date_to' => $today]);

        $revenueToday = $this->queryBuilder->computeRevenue($todayFilters);
        $ordersToday = $this->queryBuilder->computePlacedOrderCount($todayFilters);
        $activeOrders = $this->queryBuilder->activeOrders($filters)->count();

        $cancelledToday = $this->queryBuilder->cancelledOrders($todayFilters)->count();
        $cancelledRevenueToday = round(
            (float) $this->queryBuilder->cancelledOrders($todayFilters)->sum('total_amount'),
            2
        );

        $noChargeQuery = $this->queryBuilder->noChargeOrders($todayFilters);
        $noChargeToday = (clone $noChargeQuery)->count();
        $noChargeTodayAmount = round((float) (clone $noChargeQuery)->sum('total_amount'), 2);

        return [
            'revenue_today' => $revenueToday,
            'orders_today' => $ordersToday,
            'active_orders' => $activeOrders,
            'cancelled_today' => $cancelledToday,
            'cancelled_revenue_today' => $cancelledRevenueToday,
            'no_charge_today' => $noChargeToday,
            'no_charge_today_amount' => $noChargeTodayAmount,
        ];
    }

    /**
     * Per-branch today stats — used by dashboard branch cards.
     */
    public function getBranchTodayStats(int $branchId): array
    {
        $today = now()->startOfDay()->toDateString();
        $filters = ['branch_id' => $branchId, 'date_from' => $today, 'date_to' => $today];

        return [
            'revenue_today' => $this->queryBuilder->computeRevenue($filters),
            'orders_today' => $this->queryBuilder->computePlacedOrderCount($filters),
        ];
    }

    /**
     * Bulk per-branch today stats (2 queries total instead of 2N).
     *
     * @param  int[]  $branchIds
     * @return array<int, array{revenue_today: float, orders_today: int}>
     */
    public function getBranchTodayStatsBulk(array $branchIds): array
    {
        if (empty($branchIds)) {
            return [];
        }

        $today = now()->startOfDay()->toDateString();
        $filters = ['date_from' => $today, 'date_to' => $today];

        $revenueByBranch = $this->queryBuilder->revenueOrders($filters)
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id', DB::raw('SUM(total_amount) as revenue'))
            ->groupBy('branch_id')
            ->pluck('revenue', 'branch_id');

        $ordersByBranch = $this->queryBuilder->placedOrders($filters)
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id', DB::raw('COUNT(*) as orders'))
            ->groupBy('branch_id')
            ->pluck('orders', 'branch_id');

        $result = [];
        foreach ($branchIds as $id) {
            $result[$id] = [
                'revenue_today' => round((float) ($revenueByBranch[$id] ?? 0), 2),
                'orders_today' => (int) ($ordersByBranch[$id] ?? 0),
            ];
        }

        return $result;
    }

    // ─── O. REPORTS ─────────────────────────────────────────────────

    public function getDailyReport(?string $date = null): array
    {
        $date = $date ?? now()->toDateString();
        $filters = ['date_from' => $date, 'date_to' => $date];

        $totalOrders = $this->queryBuilder->computePlacedOrderCount($filters);
        $completedOrders = $this->queryBuilder->completedOrders($filters)->count();
        $cancelledOrders = $this->queryBuilder->cancelledOrders($filters)->count();
        $totalRevenue = $this->queryBuilder->computeRevenue($filters);
        $revenueOrderCount = $this->queryBuilder->computeRevenueOrderCount($filters);
        $avgOrderValue = $revenueOrderCount > 0 ? round($totalRevenue / $revenueOrderCount, 2) : 0;

        // Orders by type
        $ordersByType = $this->queryBuilder->placedOrders($filters)
            ->select('order_type', DB::raw('COUNT(*) as count'))
            ->groupBy('order_type')
            ->pluck('count', 'order_type');

        // Orders by status
        $ordersByStatus = $this->queryBuilder->placedOrders($filters)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'date' => $date,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_revenue' => $totalRevenue,
            'average_order_value' => $avgOrderValue,
            'orders_by_type' => $ordersByType,
            'orders_by_status' => $ordersByStatus,
        ];
    }

    public function getMonthlyReport(?int $year = null, ?int $month = null): array
    {
        $year = $year ?? now()->year;
        $month = $month ?? now()->month;

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();
        $filters = ['date_from' => $startDate, 'date_to' => $endDate];

        $totalOrders = $this->queryBuilder->computePlacedOrderCount($filters);
        $completedOrders = $this->queryBuilder->completedOrders($filters)->count();
        $cancelledOrders = $this->queryBuilder->cancelledOrders($filters)->count();
        $totalRevenue = $this->queryBuilder->computeRevenue($filters);
        $revenueOrderCount = $this->queryBuilder->computeRevenueOrderCount($filters);
        $avgOrderValue = $revenueOrderCount > 0 ? round($totalRevenue / $revenueOrderCount, 2) : 0;

        // Daily breakdown
        $dailyBreakdown = (clone $this->queryBuilder->revenueOrders($filters))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as orders')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'year' => $year,
            'month' => $month,
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'total_revenue' => $totalRevenue,
            'average_order_value' => $avgOrderValue,
            'daily_breakdown' => $dailyBreakdown,
        ];
    }

    // ─── P. BRANCH DETAIL STATS (Manager/Partner dashboard) ────────

    /**
     * Branch-level stats used by BranchController::stats().
     * Same canonical queries, just branch-scoped.
     */
    public function getBranchDetailStats(int $branchId): array
    {
        $today = now()->startOfDay()->toDateString();
        $thisMonth = now()->startOfMonth()->toDateString();
        $todayFilters = ['branch_id' => $branchId, 'date_from' => $today, 'date_to' => $today];
        $monthFilters = ['branch_id' => $branchId, 'date_from' => $thisMonth, 'date_to' => now()->toDateString()];
        $allTimeFilters = ['branch_id' => $branchId];

        $branch = \App\Models\Branch::find($branchId);

        return [
            'total_employees' => $branch?->employees()->count() ?? 0,
            'active_employees' => $branch?->employees()->where('status', 'active')->count() ?? 0,
            'total_orders' => $this->queryBuilder->computePlacedOrderCount($allTimeFilters),
            'today_orders' => $this->queryBuilder->computePlacedOrderCount($todayFilters),
            'month_orders' => $this->queryBuilder->computePlacedOrderCount($monthFilters),
            'today_revenue' => $this->queryBuilder->computeRevenue($todayFilters),
            'month_revenue' => $this->queryBuilder->computeRevenue($monthFilters),
            'today_cancelled' => $this->queryBuilder->cancelledOrders($todayFilters)->count(),
            'today_cancelled_revenue' => round(
                (float) $this->queryBuilder->cancelledOrders($todayFilters)->sum('total_amount'),
                2
            ),
        ];
    }

    // ─── Q. TOP ITEMS FOR BRANCH (Manager dashboard) ────────────────

    /**
     * DB-aggregated top items for a branch — replaces in-memory PHP iteration.
     */
    public function getBranchTopItems(int $branchId, string $period = 'today', int $limit = 5): array
    {
        $now = now();
        $dateFrom = match ($period) {
            'week' => $now->copy()->startOfWeek(Carbon::SUNDAY)->toDateString(),
            'month' => $now->startOfMonth()->toDateString(),
            default => $now->startOfDay()->toDateString(),
        };

        $filters = ['branch_id' => $branchId, 'date_from' => $dateFrom, 'date_to' => $now->toDateString()];

        return $this->getTopItemsMetrics($filters, $limit);
    }

    // ─── R. REVENUE CHART DATA (Manager dashboard) ──────────────────

    public function getBranchRevenueChart(int $branchId, string $period = 'week'): array
    {
        $now = now();
        if ($period === 'week') {
            $startDate = $now->copy()->startOfWeek(Carbon::SUNDAY);
            $endDate = $now->copy()->endOfWeek(Carbon::SATURDAY);
        } else {
            $startDate = $now->copy()->startOfMonth();
            $endDate = $now->copy()->endOfMonth();
        }

        $filters = [
            'branch_id' => $branchId,
            'date_from' => $startDate->toDateString(),
            'date_to' => $endDate->toDateString(),
        ];

        $dailyRevenue = (clone $this->queryBuilder->revenueOrders($filters))
            ->selectRaw('DATE(created_at) as date, SUM(total_amount) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill missing dates with 0 revenue
        $chartData = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $revenue = $dailyRevenue->get($dateStr)?->revenue ?? 0;

            $chartData[] = [
                'date' => $dateStr,
                'day' => $currentDate->format('D'),
                'revenue' => (float) $revenue,
            ];

            $currentDate->addDay();
        }

        $maxRevenue = collect($chartData)->max('revenue');
        if ($maxRevenue > 0) {
            foreach ($chartData as &$data) {
                $data['percentage'] = round(($data['revenue'] / $maxRevenue) * 100);
            }
        }

        return $chartData;
    }

    // ─── S. EMPLOYEE BRANCH STATS (Staff dashboard) ─────────────────

    /**
     * Order stats for employee's branches — replaces OrderManagementService::getBranchStats().
     */
    public function getEmployeeBranchStats(array $branchIds): array
    {
        if (empty($branchIds)) {
            return [
                'pending_orders' => 0,
                'preparing_orders' => 0,
                'today_orders' => 0,
                'today_revenue' => 0,
                'completed_today' => 0,
            ];
        }

        $today = now()->startOfDay()->toDateString();
        $todayFilters = [
            'date_from' => $today,
            'date_to' => $today,
            'branch_ids' => $branchIds,
        ];

        $branchFilter = ['branch_ids' => $branchIds];

        return [
            'pending_orders' => (clone $this->queryBuilder->activeOrders($branchFilter))
                ->where('status', 'received')
                ->count(),

            'preparing_orders' => (clone $this->queryBuilder->activeOrders($branchFilter))
                ->where('status', '!=', 'received')
                ->count(),

            'today_orders' => $this->queryBuilder->computePlacedOrderCount($todayFilters),

            'today_revenue' => $this->queryBuilder->computeRevenue($todayFilters),

            'completed_today' => $this->queryBuilder->completedOrders($todayFilters)->count(),
        ];
    }

    // ─── T. SALES COMPARISON (period-over-period) ───────────────────

    /**
     * Headline sales metrics for the current range plus the equivalent
     * preceding range, with percentage deltas. Powers the "▲12% vs last
     * period" chips on dashboards. Delta is null when there is no baseline
     * (previous value 0) or the range is open-ended.
     *
     * @return array{current: array{revenue: float, orders: int, aov: float}, previous: array{revenue: float, orders: int, aov: float}|null, delta: array{revenue: float|null, orders: float|null, aov: float|null}|null, previous_range: array{date_from: string, date_to: string}|null}
     */
    public function getSalesComparison(array $filters): array
    {
        $revenue = $this->queryBuilder->computeRevenue($filters);
        $orders = $this->queryBuilder->computePlacedOrderCount($filters);
        $revenueOrderCount = $this->queryBuilder->computeRevenueOrderCount($filters);
        $aov = $revenueOrderCount > 0 ? round($revenue / $revenueOrderCount, 2) : 0.0;

        $current = ['revenue' => $revenue, 'orders' => $orders, 'aov' => $aov];

        $previousFilters = $this->previousRangeFilters($filters);
        if ($previousFilters === null) {
            return ['current' => $current, 'previous' => null, 'delta' => null, 'previous_range' => null];
        }

        $prevRevenue = $this->queryBuilder->computeRevenue($previousFilters);
        $prevOrders = $this->queryBuilder->computePlacedOrderCount($previousFilters);
        $prevRevenueOrderCount = $this->queryBuilder->computeRevenueOrderCount($previousFilters);
        $prevAov = $prevRevenueOrderCount > 0 ? round($prevRevenue / $prevRevenueOrderCount, 2) : 0.0;

        return [
            'current' => $current,
            'previous' => ['revenue' => $prevRevenue, 'orders' => $prevOrders, 'aov' => $prevAov],
            'delta' => [
                'revenue' => $this->pctChange($revenue, $prevRevenue),
                'orders' => $this->pctChange((float) $orders, (float) $prevOrders),
                'aov' => $this->pctChange($aov, $prevAov),
            ],
            'previous_range' => [
                'date_from' => $previousFilters['date_from'],
                'date_to' => $previousFilters['date_to'],
            ],
        ];
    }

    // ─── U. REVENUE TREND (bucketed time series) ────────────────────

    /**
     * Revenue + order count grouped into time buckets (day / week / month).
     * Bucket is auto-selected from the range length when not specified, so a
     * 7-day range returns daily points while a 1-year range returns months —
     * the basis for the "trend over time" chart and growth-since-inception.
     *
     * @return array{bucket: string, series: array<int, array{period: string, revenue: float, orders: int}>}
     */
    public function getRevenueTrend(array $filters, ?string $bucket = null): array
    {
        $bucket = $this->resolveBucket($filters, $bucket);
        $driver = DB::connection()->getDriverName();
        $keyExpr = $this->bucketKeyExpression($driver, $bucket);

        $rows = (clone $this->queryBuilder->revenueOrders($filters))
            ->select(
                DB::raw("{$keyExpr} as period"),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as orders')
            )
            ->groupBy(DB::raw($keyExpr))
            ->orderBy(DB::raw($keyExpr))
            ->get();

        return [
            'bucket' => $bucket,
            'series' => $rows->map(fn ($r) => [
                'period' => (string) $r->period,
                'revenue' => round((float) $r->revenue, 2),
                'orders' => (int) $r->orders,
            ])->values()->toArray(),
        ];
    }

    // ─── PRIVATE HELPERS ────────────────────────────────────────────

    protected function computeAveragePrepTime(array $filters): ?float
    {
        $orderIds = $this->queryBuilder->placedOrders($filters)->pluck('id');

        if ($orderIds->isEmpty()) {
            return null;
        }

        return $this->computeTransitionTime($orderIds, 'preparing', ['ready', 'ready_for_pickup']);
    }

    /**
     * Compute average minutes between two status transitions from OrderStatusHistory.
     */
    protected function computeTransitionTime($orderIds, string $fromStatus, array $toStatuses): ?float
    {
        $fromTimes = OrderStatusHistory::whereIn('order_id', $orderIds)
            ->where('status', $fromStatus)
            ->select('order_id', DB::raw('MIN(changed_at) as started_at'))
            ->groupBy('order_id')
            ->get()
            ->keyBy('order_id');

        $toTimes = OrderStatusHistory::whereIn('order_id', $orderIds)
            ->whereIn('status', $toStatuses)
            ->select('order_id', DB::raw('MIN(changed_at) as ended_at'))
            ->groupBy('order_id')
            ->get()
            ->keyBy('order_id');

        $diffs = [];
        foreach ($fromTimes as $orderId => $from) {
            $to = $toTimes->get($orderId);
            if (! $to) {
                continue;
            }
            $diffMinutes = \Carbon\Carbon::parse($from->started_at)->diffInMinutes(\Carbon\Carbon::parse($to->ended_at));
            // Sanity filter: only 0-180 minutes
            if ($diffMinutes >= 0 && $diffMinutes <= 180) {
                $diffs[] = $diffMinutes;
            }
        }

        return count($diffs) > 0 ? round(array_sum($diffs) / count($diffs), 1) : null;
    }

    protected function getPreviousPeriodItems(array $filters, int $limit): array
    {
        $previousFilters = $this->previousRangeFilters($filters);
        if ($previousFilters === null) {
            return [];
        }

        $query = $this->queryBuilder->orderItems($previousFilters);

        return $query
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_options', 'order_items.menu_item_option_id', '=', 'menu_item_options.id')
            ->select(
                'menu_items.name',
                DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label) as size_label'),
                DB::raw('SUM(order_items.subtotal) as revenue')
            )
            ->groupBy('menu_items.name', DB::raw('COALESCE(menu_item_options.display_name, menu_item_options.option_label)'))
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->mapWithKeys(function ($item) {
                $key = $item->name.'|'.($item->size_label ?? '');

                return [$key => $item->revenue];
            })
            ->toArray();
    }

    /**
     * Shift a filter set back by one equivalent period (same length, ending
     * the day before date_from). Returns null when the range is open-ended.
     */
    protected function previousRangeFilters(array $filters): ?array
    {
        if (empty($filters['date_from']) || empty($filters['date_to'])) {
            return null;
        }

        $from = new \DateTime($filters['date_from']);
        $to = new \DateTime($filters['date_to']);
        $days = (int) $from->diff($to)->days;

        $previousTo = (clone $from)->modify('-1 day');
        $previousFrom = (clone $previousTo)->modify("-{$days} days");

        return array_merge($filters, [
            'date_from' => $previousFrom->format('Y-m-d'),
            'date_to' => $previousTo->format('Y-m-d'),
        ]);
    }

    /**
     * Percentage change from previous to current. Null when there is no
     * baseline (previous is 0) to avoid divide-by-zero / infinite growth.
     */
    protected function pctChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Resolve the trend bucket: honour an explicit value, otherwise pick by
     * range length (≤31d → day, ≤120d → week, else month).
     */
    protected function resolveBucket(array $filters, ?string $bucket): string
    {
        if (in_array($bucket, ['day', 'week', 'month'], true)) {
            return $bucket;
        }

        if (empty($filters['date_from']) || empty($filters['date_to'])) {
            return 'day';
        }

        $days = (int) (new \DateTime($filters['date_from']))->diff(new \DateTime($filters['date_to']))->days;

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    /**
     * Driver-aware SQL expression producing a lexically-sortable bucket key
     * (e.g. 2026-06-12 / 2026-W24 / 2026-06). Supports pgsql and mysql.
     */
    protected function bucketKeyExpression(string $driver, string $bucket): string
    {
        $isPg = $driver === 'pgsql';

        return match ($bucket) {
            'month' => $isPg ? "TO_CHAR(created_at, 'YYYY-MM')" : "DATE_FORMAT(created_at, '%Y-%m')",
            'week' => $isPg ? "TO_CHAR(created_at, 'IYYY-\"W\"IW')" : "DATE_FORMAT(created_at, '%x-W%v')",
            default => $isPg ? "TO_CHAR(created_at, 'YYYY-MM-DD')" : "DATE_FORMAT(created_at, '%Y-%m-%d')",
        };
    }
}
