<?php

namespace App\Observers;

use App\Events\OrderBroadcastEvent;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ShiftOrder;
use App\Notifications\HighValueOrderNotification;
use App\Notifications\NewOrderNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderCompletedNotification;
use App\Notifications\OrderConfirmedNotification;
use App\Notifications\OrderOutForDeliveryNotification;
use App\Notifications\OrderPreparingNotification;
use App\Notifications\OrderReadyNotification;

class OrderObserver
{
    /**
     * Handle the Order "creating" event — default the delivery-fee collection
     * state. A fee > 0 means the rider has something to collect (pending);
     * otherwise it is not applicable. Already-collected/explicit values are kept.
     */
    public function creating(Order $order): void
    {
        // Respect an explicitly-provided collection state.
        if (! in_array($order->delivery_fee_status, [null, '', 'not_applicable'], true)) {
            return;
        }

        if (((float) $order->delivery_fee) <= 0) {
            $order->delivery_fee_status = 'not_applicable';

            return;
        }

        // Orders created already in a terminal delivery state (e.g. recorded past
        // orders) are treated as already collected; otherwise the fee is pending.
        if (in_array($order->status, ['delivered', 'completed'], true)) {
            $order->delivery_fee_status = 'collected';
            $order->delivery_fee_collected_at = $order->recorded_at ?? now();
            $order->delivery_fee_collected_by = $order->assigned_employee_id;
        } else {
            $order->delivery_fee_status = 'pending';
        }
    }

    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        // Record initial status in history
        $order->statusHistory()->create([
            'status' => $order->status,
            'changed_by_type' => 'system',
            'changed_at' => now(),
        ]);

        // Past orders (manual entries) should not trigger notifications or broadcasts.
        if ($order->order_source === 'manual_entry') {
            return;
        }

        // Defer notifications until after the DB transaction commits so the
        // payment row is available and we don't send SMS before payment is confirmed.
        \DB::afterCommit(function () use ($order) {
            try {
                $order->loadMissing('payments');
                $payment = $order->payments->first();
                $isPaid = $payment && in_array($payment->payment_status, ['completed', 'no_charge']);

                // Only send order-confirmed SMS/notification once payment is confirmed.
                if ($isPaid) {
                    $order->customer?->user?->notify(new OrderConfirmedNotification($order));
                }

                // Notify all active employees at the branch
                $this->notifyBranchEmployees($order);

                // Notify manager for high value orders
                if ($order->total_amount > 200) {
                    $this->notifyBranchManager($order);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('OrderObserver created notification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            OrderBroadcastEvent::dispatch($order, 'created');
        });
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // Only act if status changed
        if (! $order->wasChanged('status')) {
            return;
        }

        // Past orders (manual entries) should not trigger notifications or broadcasts.
        if ($order->order_source === 'manual_entry') {
            return;
        }

        // Record status change in history
        try {
            $order->statusHistory()->create([
                'status' => $order->status,
                'changed_by_type' => 'system',
                'changed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('OrderObserver: statusHistory create failed', [
                'order_id' => $order->id,
                'status' => $order->status,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $customer = $order->customer?->user;

            match ($order->status) {
                'preparing' => $customer?->notify(new OrderPreparingNotification($order)),
                'ready', 'ready_for_pickup' => $customer?->notify(new OrderReadyNotification($order)),
                'out_for_delivery' => $customer?->notify(new OrderOutForDeliveryNotification($order)),
                'completed', 'delivered' => $customer?->notify(new OrderCompletedNotification($order)),
                'cancelled' => $customer?->notify(new OrderCancelledNotification($order)),
                default => null,
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('OrderObserver updated notification failed', [
                'order_id' => $order->id,
                'status' => $order->status,
                'error' => $e->getMessage(),
            ]);
        }

        // Mark the third-party delivery fee collected once the order is delivered —
        // the rider hands it over on delivery. Only acts on still-pending fees.
        if (in_array($order->status, ['delivered', 'completed'], true) && $order->delivery_fee_status === 'pending') {
            $order->updateQuietly([
                'delivery_fee_status' => 'collected',
                'delivery_fee_collected_at' => now(),
                'delivery_fee_collected_by' => $order->assigned_employee_id,
            ]);
        }

        // Handle cancellation side-effects: auto-refund payment + fix shift counters
        if ($order->status === 'cancelled') {
            $this->handleCancellationSideEffects($order);
        }

        // Dispatch broadcast after transaction commits (matches created() pattern)
        \DB::afterCommit(function () use ($order) {
            OrderBroadcastEvent::dispatch($order, 'updated');
        });
    }

    /**
     * Notify all active employees at the branch.
     */
    protected function notifyBranchEmployees(Order $order): void
    {
        $employees = Employee::whereHas('branches', fn ($q) => $q->where('branches.id', $order->branch_id))
            ->where('status', 'active')
            ->with('user')
            ->get();

        foreach ($employees as $employee) {
            $employee->user?->notify(new NewOrderNotification($order));
        }
    }

    /**
     * Notify branch manager for high value orders.
     */
    protected function notifyBranchManager(Order $order): void
    {
        $manager = Employee::whereHas('branches', fn ($q) => $q->where('branches.id', $order->branch_id))
            ->whereHas('user.roles', fn ($q) => $q->where('name', 'manager'))
            ->with('user')
            ->first();

        $manager?->user?->notify(new HighValueOrderNotification($order));
    }

    /**
     * Handle cancellation side-effects: auto-refund completed payments and fix shift counters.
     */
    protected function handleCancellationSideEffects(Order $order): void
    {
        // Auto-refund: flip completed payments to refunded (skip no_charge — nothing to refund)
        $order->loadMissing('payments');
        foreach ($order->payments as $payment) {
            if ($payment->payment_status === 'completed') {
                $payment->update([
                    'payment_status' => 'refunded',
                    'refunded_at' => now(),
                ]);
            }
        }

        // Fix shift counters: find any ShiftOrder for this order and decrement
        $shiftOrders = ShiftOrder::where('order_id', $order->id)->with('shift')->get();
        foreach ($shiftOrders as $shiftOrder) {
            $shift = $shiftOrder->shift;
            if ($shift) {
                $shift->decrement('total_sales', (float) $shiftOrder->order_total);
                $shift->decrement('order_count');
            }
            $shiftOrder->delete();
        }
    }
}
