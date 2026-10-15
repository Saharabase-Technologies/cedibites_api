<?php

namespace App\Services\Payments;

use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sends each branch code payment to the till of the branch it was for (D26, D35).
 *
 * Hubtel posts every branch's payments to one address and names no branch
 * (D20). Its reference has the same prefix for every branch, and the number
 * at its end is one count across all of Hubtel. So the branch is worked out
 * from what the payment does carry: the payer's number, the amount and the
 * time. The rules are tried in order, and the first that names one branch
 * places the payment. What none of them can place waits on a list every till
 * sees, and the first cashier to take it places it.
 *
 * Tried on the 59 real branch code payments of 2026-10-06 to 2026-10-14,
 * against prod's orders: 42 placed and 17 shared. Where two rules both named
 * a branch they agreed 20 times in 21.
 */
class BranchCodeRouter
{
    /** An order with the payer's number and the amount. */
    public const BY_NUMBER_AND_AMOUNT = 'number_amount';

    /** Orders of the amount at one branch only. */
    public const BY_AMOUNT = 'amount';

    /** The payer's number has only ever ordered at one branch. */
    public const BY_HISTORY = 'history';

    /** A cashier took it from the shared list. */
    public const BY_CASHIER = 'cashier';

    /** Minutes before and after the payment an order may have been rung. */
    private const NUMBER_AND_AMOUNT_WINDOW = [90, 30];

    private const AMOUNT_WINDOW = [30, 10];

    private const HISTORY_DAYS = 90;

    /**
     * A shared payment is tried again each time an order is rung, for this long
     * after it was paid. It matches the widest "after" window above: an order
     * rung later than this can no longer match.
     */
    public const RETRY_MINUTES = 30;

    /**
     * Place one payment on a branch if a rule can. A payment already placed,
     * or one a cashier sent back, is left alone.
     */
    public function route(HubtelIncomingPayment $payment): bool
    {
        if ($payment->branch_id !== null || $payment->returned_at !== null) {
            return false;
        }

        $placement = $this->byNumberAndAmount($payment)
            ?? $this->byAmount($payment)
            ?? $this->byHistory($payment);

        if ($placement === null) {
            return false;
        }

        [$by, $branchId, $orderId] = $placement;

        // Only while it is still shared: a cashier may have taken it meanwhile.
        $placed = HubtelIncomingPayment::whereKey($payment->id)
            ->whereNull('branch_id')
            ->whereNull('returned_at')
            ->update([
                'branch_id' => $branchId,
                'routed_by' => $by,
                'routed_at' => now(),
                'matched_order_id' => $orderId,
            ]) === 1;

        $payment->refresh();

        return $placed;
    }

    /**
     * Try the rules again on the shared payments of the last half hour.
     *
     * A customer often pays by the branch code before the cashier rings the
     * sale, so the order a rule needs may only now exist.
     */
    public function routeShared(): int
    {
        return HubtelIncomingPayment::whereNull('branch_id')
            ->whereNull('returned_at')
            ->where('paid_at', '>=', now()->subMinutes(self::RETRY_MINUTES))
            ->orderBy('paid_at')
            ->get()
            ->filter(fn (HubtelIncomingPayment $payment) => $this->route($payment))
            ->count();
    }

    /** Is anything on the shared list young enough to be placed by a new order? */
    public function hasRecentShared(): bool
    {
        return HubtelIncomingPayment::whereNull('branch_id')
            ->whereNull('returned_at')
            ->where('paid_at', '>=', now()->subMinutes(self::RETRY_MINUTES))
            ->exists();
    }

    /**
     * A cashier takes a shared payment for their branch. When two tills press
     * at once, the first wins and the second is refused.
     */
    public function take(HubtelIncomingPayment $payment, int $branchId, Employee $employee): bool
    {
        return HubtelIncomingPayment::whereKey($payment->id)
            ->whereNull('branch_id')
            ->update([
                'branch_id' => $branchId,
                'routed_by' => self::BY_CASHIER,
                'routed_at' => now(),
                'matched_order_id' => null,
                'taken_by' => $employee->id,
            ]) === 1;
    }

    /**
     * A cashier says a payment on their till is not theirs. It goes back to the
     * shared list, and no rule places it again: a person has ruled on it.
     */
    public function giveBack(HubtelIncomingPayment $payment, int $branchId, Employee $employee): bool
    {
        return HubtelIncomingPayment::whereKey($payment->id)
            ->where('branch_id', $branchId)
            ->update([
                'branch_id' => null,
                'routed_by' => null,
                'routed_at' => null,
                'matched_order_id' => null,
                'taken_by' => null,
                'returned_at' => now(),
                'returned_by' => $employee->id,
            ]) === 1;
    }

    /** Rule 1: the payer's number and the amount, on one order. */
    private function byNumberAndAmount(HubtelIncomingPayment $payment): ?array
    {
        $phones = $this->phoneForms($payment->payer_number);

        if ($phones === []) {
            return null;
        }

        $orders = $this->ordersAround($payment, ...self::NUMBER_AND_AMOUNT_WINDOW)
            ->whereIn('contact_phone', $phones)
            ->get(['id', 'branch_id', 'total_amount', 'delivery_fee', 'created_at'])
            ->filter(fn (Order $order) => $this->sameAmount($order, $payment));

        return $this->oneBranch($orders, $payment, self::BY_NUMBER_AND_AMOUNT);
    }

    /** Rule 2: orders of the amount at one branch and no other. */
    private function byAmount(HubtelIncomingPayment $payment): ?array
    {
        $orders = $this->ordersAround($payment, ...self::AMOUNT_WINDOW)
            ->get(['id', 'branch_id', 'total_amount', 'delivery_fee', 'created_at'])
            ->filter(fn (Order $order) => $this->sameAmount($order, $payment));

        return $this->oneBranch($orders, $payment, self::BY_AMOUNT);
    }

    /** Rule 3: the payer's number has only ever ordered at one branch. */
    private function byHistory(HubtelIncomingPayment $payment): ?array
    {
        $phones = $this->phoneForms($payment->payer_number);

        if ($phones === []) {
            return null;
        }

        $branches = Order::query()
            ->whereIn('contact_phone', $phones)
            ->whereNotNull('branch_id')
            ->where('created_at', '<', $payment->paid_at)
            ->where('created_at', '>=', $payment->paid_at->copy()->subDays(self::HISTORY_DAYS))
            ->distinct()
            ->pluck('branch_id');

        return $branches->count() === 1 ? [self::BY_HISTORY, $branches->first(), null] : null;
    }

    private function ordersAround(HubtelIncomingPayment $payment, int $before, int $after): Builder
    {
        return Order::query()
            ->whereNotNull('branch_id')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [
                $payment->paid_at->copy()->subMinutes($before),
                $payment->paid_at->copy()->addMinutes($after),
            ]);
    }

    /**
     * Several orders may share the amount. They must all be at one branch, and
     * the one nearest in time is taken as the match.
     */
    private function oneBranch(Collection $orders, HubtelIncomingPayment $payment, string $by): ?array
    {
        if ($orders->isEmpty() || $orders->pluck('branch_id')->unique()->count() !== 1) {
            return null;
        }

        $nearest = $orders
            ->sortBy(fn (Order $order) => abs($order->created_at->diffInSeconds($payment->paid_at)))
            ->first();

        return [$by, $nearest->branch_id, $nearest->id];
    }

    /** The goods alone, or the goods with delivery: a customer may pay either. */
    private function sameAmount(Order $order, HubtelIncomingPayment $payment): bool
    {
        $amount = (float) $payment->amount;
        $total = (float) $order->total_amount;

        return abs($total - $amount) < 0.005
            || abs($total - (float) $order->delivery_fee - $amount) < 0.005;
    }

    /**
     * Every form a number is kept in on orders: +233247879103 since orders
     * were normalised, 0247879103 and 233247879103 on older rows.
     */
    private function phoneForms(?string $number): array
    {
        if (! $number || ! preg_match('/(\d{9})$/', $number, $m)) {
            return [];
        }

        return ['+233'.$m[1], '0'.$m[1], '233'.$m[1]];
    }
}
