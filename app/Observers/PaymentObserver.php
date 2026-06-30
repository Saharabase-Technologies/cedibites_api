<?php

namespace App\Observers;

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Models\Employee;
use App\Models\Payment;
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

        // Notify customer when payment fails
        if ($payment->payment_status === 'failed') {
            $payment->customer?->user?->notify(new PaymentFailedNotification($payment));

            // Also notify branch manager
            $this->notifyBranchManager($payment);
        }

        // Auto-deduct recipe ingredients once payment is confirmed; reverse on refund.
        if (in_array($payment->payment_status, self::PAID_STATES, true)) {
            $this->deductStock($payment);
        } elseif ($payment->payment_status === 'refunded') {
            $this->reverseStock($payment);
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
        $manager = Employee::where('branch_id', $payment->order->branch_id)
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'manager'))
            ->with('user')
            ->first();

        $manager?->user?->notify(new PaymentFailedNotification($payment));
    }
}
