<?php

namespace App\Observers;

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftOrder;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentObserver
{
    /** Payment states that mean the order is finalized and stock should be deducted. */
    private const PAID_STATES = ['completed', 'no_charge'];

    /**
     * Handle the Payment "created" event. POS/cash orders are often created with
     * payment already completed, so deduct here too.
     */
    public function created(Payment $payment): void
    {
        if (in_array($payment->payment_status, self::PAID_STATES, true)) {
            $this->deductStock($payment);
            $this->recordOnShift($payment);
            $this->markFullyPaid($payment);
        }
    }

    /**
     * Handle the Payment "updated" event.
     */
    public function updated(Payment $payment): void
    {
        // Only act if payment status changed
        if (! $payment->wasChanged('payment_status')) {
            return;
        }

        // Notify customer when payment fails. Guarded: a message that cannot
        // be sent must never undo the payment's own update.
        if ($payment->payment_status === 'failed') {
            try {
                $payment->customer?->user?->notify(new PaymentFailedNotification($payment));

                // Also notify branch manager
                $this->notifyBranchManager($payment);
            } catch (\Throwable $e) {
                Log::warning('Could not tell anyone a payment failed', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Auto-deduct recipe ingredients once payment is confirmed; reverse on refund.
        if (in_array($payment->payment_status, self::PAID_STATES, true)) {
            $this->deductStock($payment);
            $this->recordOnShift($payment);
            $this->markFullyPaid($payment);
        } elseif ($payment->payment_status === 'refunded') {
            // One part refunded on an order that keeps going is not the food
            // coming back (T3). Stock returns only when the order is
            // cancelled, or nothing paid is left on it.
            $order = $payment->order()->with('payments')->first();

            if ($order && ($order->status === 'cancelled' || $order->amount_paid <= 0)) {
                $this->reverseStock($payment);
            }
        }
    }

    /**
     * Deduct recipe ingredients for the paid order. Runs after commit, is fully
     * idempotent, and never breaks the payment flow on error.
     */
    protected function deductStock(Payment $payment): void
    {
        DB::afterCommit(function () use ($payment) {
            try {
                $order = $payment->order;
                if ($order) {
                    app(RecipeDeductionService::class)->deductForOrder($order);
                }
            } catch (\Throwable $e) {
                Log::error('Recipe deduction failed', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Put the sale on the shift of whoever took the money (D9).
     *
     * The till's browser used to do this after each sale, and only for a sale
     * that confirmed on the spot, so no MoMo prompt sale ever reached a shift
     * (T12). The browser's own call may still arrive for a while; the unique
     * shift and order pair keeps the sale on the shift once.
     */
    protected function recordOnShift(Payment $payment): void
    {
        if (! $payment->shift_id) {
            return;
        }

        DB::afterCommit(function () use ($payment) {
            try {
                $order = $payment->order;

                if (! $order) {
                    return;
                }

                $shiftOrder = ShiftOrder::firstOrCreate(
                    ['shift_id' => $payment->shift_id, 'order_id' => $order->id],
                    ['order_total' => $order->total_amount],
                );

                if ($shiftOrder->wasRecentlyCreated) {
                    Shift::whereKey($payment->shift_id)->increment('total_sales', (float) $order->total_amount);
                    Shift::whereKey($payment->shift_id)->increment('order_count');
                }
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // The browser's call won the race. The sale is on the shift once.
            } catch (\Throwable $e) {
                Log::warning('Could not put a sale on its shift', [
                    'payment_id' => $payment->id,
                    'shift_id' => $payment->shift_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * When the payments first cover the order, say when, once. A sale paid
     * on the spot is fully paid as it is made; a Pay later order when its
     * last part comes in.
     */
    protected function markFullyPaid(Payment $payment): void
    {
        DB::afterCommit(function () use ($payment) {
            try {
                $order = Order::with('payments')->find($payment->order_id);

                if ($order && $order->fully_paid_at === null && $order->balance_due <= 0) {
                    Order::whereKey($order->id)->whereNull('fully_paid_at')->update(['fully_paid_at' => now()]);
                }
            } catch (\Throwable $e) {
                Log::warning('Could not mark an order fully paid', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    protected function reverseStock(Payment $payment): void
    {
        DB::afterCommit(function () use ($payment) {
            try {
                $order = $payment->order;
                if ($order) {
                    app(RecipeDeductionService::class)->reverseForOrder($order);
                }
            } catch (\Throwable $e) {
                Log::error('Recipe deduction reversal failed', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Notify branch manager about payment issues.
     */
    protected function notifyBranchManager(Payment $payment): void
    {
        // Employees reach branches through employee_branch. There is no
        // employees.branch_id, so the old where('branch_id') was an error.
        $manager = Employee::whereHas('branches', fn ($q) => $q->where('branches.id', $payment->order->branch_id))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'manager'))
            ->with('user')
            ->first();

        $manager?->user?->notify(new PaymentFailedNotification($payment));
    }
}
