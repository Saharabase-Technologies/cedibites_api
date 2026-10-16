<?php

namespace App\Services\Finance;

use App\Services\Analytics\AnalyticsQueryBuilder;
use Illuminate\Database\Eloquent\Builder as OrderQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The rows the Finance screens are worked out from.
 *
 * It returns compact rows and no totals. The frontend adds them up, so a
 * screen and its download can never disagree.
 *
 * Which orders count is not decided here. Every query takes its orders from
 * AnalyticsQueryBuilder, so Finance and Analytics agree on what a sale is: a
 * paid order that was not cancelled, worth its total less the delivery fee.
 */
class FinanceLedgerService
{
    /**
     * What became of the money on an order cancelled after payment, read from
     * the payment's status.
     */
    private const OUTCOMES = [
        'refunded' => 'refunded',
        'completed' => 'kept',
        'no_charge' => 'no_charge',
    ];

    public function __construct(
        protected AnalyticsQueryBuilder $orders,
    ) {}

    /**
     * The branch keys must come from ScopesToAssignedBranches and from nowhere
     * else. `[-1]` is a real scope that matches nothing.
     *
     * @param  array{date_from?: string, date_to?: string, branch_id?: int|string, branch_ids?: list<int>}  $filters
     * @return array<string, mixed>
     */
    public function ledger(array $filters): array
    {
        $now = now();
        $items = $this->items($filters);

        return [
            'read_at' => $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'today' => $now->toDateString(),
            'branches' => $this->branches($filters),
            'sales' => $this->sales($filters),
            'cancelled' => $this->cancelled($filters),
            'items' => $items,
            'options' => $this->options($items),
        ];
    }

    /**
     * The branches in scope, each with the day of its first paid order.
     *
     * The first sale ignores the dates asked for: it is a fact about the
     * branch, and "months since opening" needs it whatever the window is. A
     * closed branch is listed only if it sold something, so its rows can still
     * be named.
     *
     * @return list<array{id: int, name: string, first_sale: string|null}>
     */
    private function branches(array $filters): array
    {
        $scope = Arr::only($filters, ['branch_id', 'branch_ids']);

        $firstSales = $this->orders->revenueOrders($scope)
            ->selectRaw('branch_id, MIN(DATE(created_at)) as first_sale')
            ->groupBy('branch_id')
            ->toBase()
            ->pluck('first_sale', 'branch_id');

        return DB::table('branches')
            ->when(isset($scope['branch_ids']), fn (Builder $q) => $q->whereIn('id', $scope['branch_ids']))
            ->when(isset($scope['branch_id']), fn (Builder $q) => $q->where('id', $scope['branch_id']))
            ->orderBy('id')
            ->get(['id', 'name', 'deleted_at'])
            ->filter(fn ($branch) => $branch->deleted_at === null || $firstSales->has($branch->id))
            ->map(fn ($branch) => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'first_sale' => $firstSales->has($branch->id) ? (string) $firstSales[$branch->id] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Sales by day, branch, source, type and payment method.
     *
     * Columns: date, branch, source, type, method, paid orders, gross,
     * discount, service charge, net, delivery fee, platform commission.
     *
     * Platform commission is what Bolt kept of its orders. It is last so the
     * columns before it keep their places. Gross less discount, plus service
     * charge, less platform commission, is net.
     *
     * @return list<array<int, string|int|float>>
     */
    private function sales(array $filters): array
    {
        $net = AnalyticsQueryBuilder::revenueExpr('o');
        $keys = 'DATE(o.created_at), o.branch_id, o.order_source, o.order_type, p.payment_method';

        return $this->paidOrders($filters)
            ->selectRaw('DATE(o.created_at) as day, o.branch_id, o.order_source, o.order_type, p.payment_method')
            ->selectRaw('COUNT(*) as paid_orders, SUM(o.subtotal) as gross')
            ->selectRaw('SUM(COALESCE(o.discount, 0)) as discount, SUM(COALESCE(o.service_charge, 0)) as service_charge')
            ->selectRaw("SUM({$net}) as net, SUM(COALESCE(o.delivery_fee, 0)) as delivery_fee")
            ->selectRaw('SUM(COALESCE(o.platform_commission, 0)) as platform_commission')
            ->groupByRaw($keys)
            ->orderByRaw($keys)
            ->get()
            ->map(fn ($row) => [
                (string) $row->day,
                (int) $row->branch_id,
                (string) $row->order_source,
                (string) $row->order_type,
                (string) $row->payment_method,
                (int) $row->paid_orders,
                $this->money($row->gross),
                $this->money($row->discount),
                $this->money($row->service_charge),
                $this->money($row->net),
                $this->money($row->delivery_fee),
                $this->money($row->platform_commission),
            ])
            ->all();
    }

    /**
     * Every order cancelled after payment, oldest first. None of them is a
     * sale. `amount` leaves out the delivery fee, as net sales does.
     *
     * @return list<array<string, string|int|float|null>>
     */
    private function cancelled(array $filters): array
    {
        $amount = AnalyticsQueryBuilder::revenueExpr('o');

        return $this->withPayment($this->orders->cancelledOrders($filters), array_keys(self::OUTCOMES))
            ->selectRaw('o.order_number, o.created_at, o.cancelled_at, o.branch_id, o.order_source, o.order_type')
            ->selectRaw("p.payment_method, p.payment_status, o.cancelled_reason, {$amount} as amount")
            ->orderBy('o.created_at')
            ->orderBy('o.id')
            ->get()
            ->map(fn ($row) => [
                'order_number' => (string) $row->order_number,
                'placed_at' => $this->minute($row->created_at),
                'cancelled_at' => $this->minute($row->cancelled_at),
                'branch_id' => (int) $row->branch_id,
                'source' => (string) $row->order_source,
                'type' => (string) $row->order_type,
                'method' => (string) $row->payment_method,
                'outcome' => self::OUTCOMES[$row->payment_status],
                'amount' => $this->money($row->amount),
                'reason' => trim((string) $row->cancelled_reason),
            ])
            ->all();
    }

    /**
     * Items sold by month, branch, source, type, payment method and option.
     *
     * Columns: month, branch, source, type, method, option id, quantity,
     * sales. A line sold without an option comes back under option 0.
     *
     * A Bolt line counts its share of what we kept: its listed price scaled
     * by the order's net over its listed subtotal. Every other line counts as
     * it always has.
     *
     * @return list<array<int, string|int|float>>
     */
    private function items(array $filters): array
    {
        $month = $this->monthOf('o.created_at');
        $option = 'COALESCE(oi.menu_item_option_id, 0)';
        $keys = "{$month}, o.branch_id, o.order_source, o.order_type, p.payment_method, {$option}";
        $lineSales = 'CASE WHEN COALESCE(o.platform_commission, 0) > 0 AND o.subtotal > 0'
            .' THEN oi.subtotal * (o.subtotal - COALESCE(o.discount, 0) - o.platform_commission) / o.subtotal'
            .' ELSE oi.subtotal END';

        return \App\Models\OrderItem::leaveOutCancelledRounds($this->paidOrders($filters)
            ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->whereNull('oi.deleted_at'), 'oi')
            ->selectRaw("{$month} as month, o.branch_id, o.order_source, o.order_type, p.payment_method")
            ->selectRaw("{$option} as option_id, SUM(oi.quantity) as quantity, SUM({$lineSales}) as sales")
            ->groupByRaw($keys)
            ->orderByRaw($keys)
            ->get()
            ->map(fn ($row) => [
                (string) $row->month,
                (int) $row->branch_id,
                (string) $row->order_source,
                (string) $row->order_type,
                (string) $row->payment_method,
                (int) $row->option_id,
                (int) $row->quantity,
                $this->money($row->sales),
            ])
            ->all();
    }

    /**
     * How each option sold is named today: its dish, its receipt name, its
     * label and its category, keyed by option id.
     *
     * The frontend names the line. It must not be joined here. An option that
     * was deleted from the menu is still named, because its sales still count.
     *
     * @param  list<array<int, string|int|float>>  $items
     */
    private function options(array $items): object
    {
        $ids = collect($items)->pluck(5)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return (object) [];
        }

        return (object) DB::table('menu_item_options as mio')
            ->join('menu_items as mi', 'mi.id', '=', 'mio.menu_item_id')
            ->leftJoin('menu_categories as c', 'c.id', '=', 'mi.category_id')
            ->whereIn('mio.id', $ids)
            ->orderBy('mio.id')
            ->get(['mio.id', 'mi.name as item', 'mio.display_name', 'mio.option_label', 'c.name as category'])
            ->mapWithKeys(fn ($option) => [(int) $option->id => [
                'item' => trim((string) $option->item),
                'display_name' => trim((string) $option->display_name),
                'label' => trim((string) $option->option_label),
                'category' => trim((string) $option->category) ?: 'Uncategorised',
            ]])
            ->all();
    }

    /**
     * Paid orders that were not cancelled, each with the payment that paid it.
     */
    private function paidOrders(array $filters): Builder
    {
        return $this->withPayment($this->orders->revenueOrders($filters), ['completed']);
    }

    /**
     * The given orders, each joined to one payment.
     *
     * The orders are chosen by AnalyticsQueryBuilder and by nothing here. The
     * join is only there to read the payment's method and status. An order
     * counts once, under its first payment of the statuses named, so paying an
     * order in two parts can never double a sale.
     *
     * @param  list<string>  $statuses
     */
    private function withPayment(OrderQuery $orders, array $statuses): Builder
    {
        $marks = implode(', ', array_fill(0, count($statuses), '?'));

        return DB::table('orders as o')
            ->join('payments as p', 'p.order_id', '=', 'o.id')
            ->whereIn('o.id', $orders->select('orders.id'))
            ->whereRaw(
                'p.id = (select min(fp.id) from payments fp where fp.order_id = o.id'
                ." and fp.deleted_at is null and fp.payment_status in ({$marks}))",
                $statuses,
            );
    }

    /**
     * The month a timestamp falls in, as `2026-03`, in the database's own words.
     */
    private function monthOf(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR({$column}, 'YYYY-MM')",
            'mysql', 'mariadb' => "DATE_FORMAT({$column}, '%Y-%m')",
            default => "strftime('%Y-%m', {$column})",
        };
    }

    /**
     * A timestamp to the minute, as `2026-04-03 13:22`.
     */
    private function minute(?string $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->format('Y-m-d H:i');
    }

    private function money(mixed $amount): float
    {
        return round((float) $amount, 2);
    }
}
