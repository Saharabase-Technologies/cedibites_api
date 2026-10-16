<?php

namespace App\Services\Payments;

use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The money taken, by method, from the payments themselves (D11).
 *
 * Never one mixed total: cash is counted against the drawer, MoMo is checked
 * with Hubtel, and adding them together hides a short drawer behind a busy
 * MoMo day.
 */
class ShiftMoney
{
    /** How each payment method is counted at the end of a shift. */
    private const GROUPS = [
        'cash' => 'cash',
        'cash_on_delivery' => 'cash',
        // A prompt the till sent, which Hubtel confirmed. A branch code
        // payment is a mobile_money payment too, and is split out: see groupOf.
        'mobile_money' => 'momo',
        // A transfer the cashier typed a reference for. Only their word.
        'manual_momo' => 'manual_momo',
        'card' => 'card',
        'wallet' => 'other',
        'ghqr' => 'other',
    ];

    /** Every group, in the order the screens show them. The response is flat. */
    public const KEYS = ['cash', 'momo', 'ussd', 'manual_momo', 'card', 'other'];

    /**
     * The group a payment counts in.
     *
     * USSD is a payment the customer made by dialling the branch code, linked
     * to an order at the till. It is stored as mobile_money, like a prompt,
     * and only its source tells the two apart. A hubtel_incoming_payments row
     * points at every such payment; the migration of 2026-10-16 stamped the
     * source on the few linked before the source column existed.
     */
    public static function groupOf(string $method, ?string $source): string
    {
        if ($method === 'mobile_money' && $source === PaymentCollector::SOURCE_BRANCH_CODE_LINK) {
            return 'ussd';
        }

        return self::GROUPS[$method] ?? 'other';
    }

    /**
     * What one shift took.
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public function for(Shift $shift): array
    {
        return $this->sum(Payment::where('shift_id', $shift->id));
    }

    /**
     * What one person took between two times, on a shift or not (D9).
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public function forCollector(int $employeeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->sum(Payment::where('collected_by', $employeeId)->whereBetween('paid_at', [$from, $to]));
    }

    /**
     * The food given free on the person's own orders, placed between two
     * times. Not money and never in a total: My Sales shows it beside the
     * money so a No Charge order is still seen. Cancelled orders are left out.
     *
     * @return array{amount: float, count: int}
     */
    public function noChargeFor(int $employeeId, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = Order::where('assigned_employee_id', $employeeId)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->whereHas('payments', fn (Builder $q) => $q->where('payment_status', 'no_charge'))
            ->get(['id', 'total_amount', 'delivery_fee']);

        return [
            'amount' => round((float) $orders->sum(fn (Order $order) => $order->goods_amount), 2),
            'count' => $orders->count(),
        ];
    }

    /**
     * The money the person took on orders that are not theirs, between two
     * times. One entry for each order and group: a payment by cash and one by
     * MoMo on the same order are two lines, because Clock out says which
     * money came from where. Whose the order is follows assigned_employee_id,
     * as My Sales does (D13).
     *
     * @return list<array{order_id: int, order_number: string, assigned_to: ?string, group: string, amount: float, count: int}>
     */
    public function onOthersOrders(int $employeeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->others(
            Payment::query()->where('payments.collected_by', $employeeId)->whereBetween('payments.paid_at', [$from, $to]),
            $employeeId,
        );
    }

    /**
     * The same, for the payments one shift took. Clock out uses it.
     *
     * @return list<array{order_id: int, order_number: string, assigned_to: ?string, group: string, amount: float, count: int}>
     */
    public function onOthersOrdersOnShift(Shift $shift): array
    {
        return $this->others(Payment::query()->where('payments.shift_id', $shift->id), $shift->employee_id);
    }

    /**
     * The person's orders still owing, placed since a time, oldest first.
     * Clock out lists them under "Still owed on your orders".
     *
     * @return list<array{order_id: int, order_number: string, customer: ?string, amount: float}>
     */
    public function owingOn(int $employeeId, CarbonInterface $since): array
    {
        return Order::with('payments')
            ->where('assigned_employee_id', $employeeId)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Order $order) => $order->balance_due > 0)
            ->map(fn (Order $order) => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->contact_name,
                'amount' => $order->balance_due,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{order_id: int, order_number: string, assigned_to: ?string, group: string, amount: float, count: int}> */
    private function others(Builder $payments, int $employeeId): array
    {
        $rows = $payments
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereNull('orders.deleted_at')
            ->where('payments.payment_status', 'completed')
            ->where(fn ($q) => $q->whereNull('orders.assigned_employee_id')
                ->orWhere('orders.assigned_employee_id', '!=', $employeeId))
            ->selectRaw('orders.id as order_id, orders.order_number, orders.assigned_employee_id, payments.payment_method, payments.source, COUNT(*) as n, SUM(payments.amount) as total, MIN(payments.paid_at) as first_paid')
            ->groupBy('orders.id', 'orders.order_number', 'orders.assigned_employee_id', 'payments.payment_method', 'payments.source')
            ->get();

        $names = Employee::with('user:id,name')
            ->whereIn('id', $rows->pluck('assigned_employee_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => strtok((string) $e->user?->name, ' ') ?: null]);

        $lines = [];
        foreach ($rows as $row) {
            $group = self::groupOf($row->payment_method, $row->source);
            $key = $row->order_id.'|'.$group;

            $lines[$key] ??= [
                'order_id' => (int) $row->order_id,
                'order_number' => $row->order_number,
                'assigned_to' => $names[$row->assigned_employee_id] ?? null,
                'group' => $group,
                'amount' => 0.0,
                'count' => 0,
                'first_paid' => (string) $row->first_paid,
            ];
            $lines[$key]['amount'] = round($lines[$key]['amount'] + (float) $row->total, 2);
            $lines[$key]['count'] += (int) $row->n;
            $lines[$key]['first_paid'] = min($lines[$key]['first_paid'], (string) $row->first_paid);
        }

        // In the order the money came in.
        usort($lines, fn (array $a, array $b) => strcmp($a['first_paid'], $b['first_paid']));

        return array_map(function (array $line) {
            unset($line['first_paid']);

            return $line;
        }, $lines);
    }

    /**
     * Everything the Clock out sheet shows for a shift, worked out now: the
     * money by method, its total, No Charge, the money from other people's
     * orders, and the person's orders still owing since the start of the day
     * the shift began. What the button stores is this, as it is then.
     *
     * @return array{money: array<string, array{amount: float, count: int}>, total: float, count: int, no_charge: array{amount: float, count: int}, on_others_orders: list<array<string, mixed>>, owing: list<array<string, mixed>>}
     */
    public function clockOutView(Shift $shift): array
    {
        $money = $this->for($shift);
        $end = $shift->logout_at ?? now();

        return [
            'money' => $money,
            'total' => round(array_sum(array_column($money, 'amount')), 2),
            'count' => array_sum(array_column($money, 'count')),
            'no_charge' => $this->noChargeFor($shift->employee_id, $shift->login_at, $end),
            'on_others_orders' => $this->onOthersOrdersOnShift($shift),
            'owing' => $this->owingOn($shift->employee_id, $shift->login_at->copy()->startOfDay()),
        ];
    }

    /** @return array<string, array{amount: float, count: int}> */
    private function sum(Builder $payments): array
    {
        $money = array_fill_keys(self::KEYS, ['amount' => 0.0, 'count' => 0]);

        $rows = $payments
            ->where('payment_status', 'completed')
            ->selectRaw('payment_method, source, COUNT(*) as n, SUM(amount) as total')
            ->groupBy('payment_method', 'source')
            ->get();

        foreach ($rows as $row) {
            $group = self::groupOf($row->payment_method, $row->source);
            $money[$group]['amount'] = round($money[$group]['amount'] + (float) $row->total, 2);
            $money[$group]['count'] += (int) $row->n;
        }

        return $money;
    }
}
