<?php

namespace App\Services\Shifts;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Services\Payments\ShiftMoney;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Staff Sales, one line a shift (staff portal Section 7, the lists).
 *
 * Each shift: who, where, when, the money it took by method now, what its
 * person confirmed at Clock out, the manager's confirm, and the person's
 * orders on it. The money comes from the payments the shift took, as My
 * Sales and Clock out count it. The orders are the ones assigned to the
 * person and placed during the shift, as My Sales counts them (D13).
 */
class ShiftSales
{
    /**
     * Every shift that touches the period, newest first.
     *
     * @param  list<int>|null  $branchIds  Null for every branch.
     * @return list<array<string, mixed>>
     */
    public function list(?array $branchIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $shifts = Shift::with(['employee.user.roles', 'branch', 'managerConfirmedBy.user'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            // The portal starts a shift for an admin too. It sells nothing and
            // nobody confirms it, so it is not a line on Staff Sales.
            ->whereDoesntHave('employee.user.roles', fn ($q) => $q->whereIn('name', [Role::Admin->value, Role::TechAdmin->value]))
            ->where('login_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('logout_at')->orWhere('logout_at', '>=', $from))
            ->orderByDesc('login_at')
            ->get();

        if ($shifts->isEmpty()) {
            return [];
        }

        $money = $this->moneyByShift($shifts);
        $orders = $this->ordersOf($shifts);
        $hasManager = Branch::whereIn('id', $shifts->pluck('branch_id')->filter()->unique())
            ->withCount('managers')
            ->pluck('managers_count', 'id');

        return $shifts->map(function (Shift $shift) use ($money, $orders, $hasManager) {
            $end = $shift->logout_at ?? now();
            $theirs = ($orders[$shift->employee_id] ?? collect())
                ->filter(fn (Order $o) => $o->created_at->between($shift->login_at, $end)
                    && ($shift->branch_id === null || $o->branch_id === $shift->branch_id));
            $live = $theirs->where('status', '!=', 'cancelled');
            $free = $live->filter(fn (Order $o) => $o->payments->contains('payment_status', 'no_charge'));
            $groups = $money[$shift->id] ?? $this->emptyGroups();

            return [
                'id' => $shift->id,
                'employee_id' => $shift->employee_id,
                'staff_name' => $shift->employee?->user?->name ?? '',
                // A manager's own shift: nobody else confirms it (Q3).
                'is_manager' => (bool) $shift->employee?->user?->hasRole(Role::Manager),
                'branch_id' => $shift->branch_id,
                'branch_name' => $shift->branch?->name,
                // No manager: the admin confirms (Q2).
                'branch_has_manager' => ($hasManager[$shift->branch_id] ?? 0) > 0,
                'login_at' => $shift->login_at->toIso8601String(),
                'logout_at' => $shift->logout_at?->toIso8601String(),
                'on_shift' => $shift->logout_at === null,
                'money' => $groups,
                'total' => round(array_sum(array_column($groups, 'amount')), 2),
                // Food given free on the person's orders. Not money, never in the total.
                'no_charge' => [
                    'amount' => round((float) $free->sum(fn (Order $o) => $o->goods_amount), 2),
                    'count' => $free->count(),
                ],
                'clock_out' => $shift->clocked_out_at ? [
                    'at' => $shift->clocked_out_at->toIso8601String(),
                    'money' => $shift->clock_out_money,
                    'owing' => $shift->clock_out_owing ?? [],
                    'note' => $shift->clock_out_note,
                ] : null,
                'manager_confirm' => $shift->manager_confirmed_at ? [
                    'at' => $shift->manager_confirmed_at->toIso8601String(),
                    'by_employee_id' => $shift->manager_confirmed_by,
                    'by_name' => $shift->managerConfirmedBy?->user?->name,
                    'note' => $shift->manager_note,
                ] : null,
                'orders_attended' => $live->count(),
                'order_value' => round((float) $live->diffKeys($free)->sum(fn (Order $o) => $o->goods_amount), 2),
                'still_owed' => round((float) $live->sum(fn (Order $o) => $o->balance_due), 2),
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, array{amount: float, count: int}>> */
    private function moneyByShift(Collection $shifts): array
    {
        $rows = Payment::whereIn('shift_id', $shifts->pluck('id'))
            ->where('payment_status', 'completed')
            ->selectRaw('shift_id, payment_method, source, COUNT(*) as n, SUM(amount) as total')
            ->groupBy('shift_id', 'payment_method', 'source')
            ->get();

        $money = [];
        foreach ($rows as $row) {
            $id = (int) $row->shift_id;
            $money[$id] ??= $this->emptyGroups();
            $group = ShiftMoney::groupOf($row->payment_method, $row->source);
            $money[$id][$group]['amount'] = round($money[$id][$group]['amount'] + (float) $row->total, 2);
            $money[$id][$group]['count'] += (int) $row->n;
        }

        return $money;
    }

    /** @return array<int, Collection<int, Order>> The people's orders over the whole span, by person. */
    private function ordersOf(Collection $shifts): array
    {
        $from = $shifts->min('login_at');
        $to = $shifts->map(fn (Shift $s) => $s->logout_at ?? now())->max();

        return Order::with('payments')
            ->whereIn('assigned_employee_id', $shifts->pluck('employee_id')->unique())
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->groupBy('assigned_employee_id')
            ->all();
    }

    /** @return array<string, array{amount: float, count: int}> */
    private function emptyGroups(): array
    {
        return array_fill_keys(ShiftMoney::KEYS, ['amount' => 0.0, 'count' => 0]);
    }
}
