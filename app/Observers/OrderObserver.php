<?php

namespace App\Observers;

use App\Events\OrderBroadcastEvent;
use App\Jobs\RequestOrderFeedback;
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
     * Who is making this change, for the status history.
     *
     * Both columns were previously hardcoded to 'system' with `changed_by_id`
     * never written at all — so `order_status_history` recorded that an order
     * moved and was silent about who moved it. The information was reachable
     * only through the activity log, and only for the handful of attributes the
     * Order model logs.
     *
     * That gap is why an order could sit in Received for the best part of three
     * hours with nothing able to name the person who took it. Anything that
     * measures staff conduct against order flow needs this column populated;
     * see App\Services\StaffMessaging.
     *
     * Falls back to 'system' outside a request — queued jobs, console commands,
     * seeders — which is what those changes genuinely are.
     *
     * @return array{changed_by_id: ?int, changed_by_type: string}
     */
    private function causer(): array
    {
        $user = auth()->user();

        if (! $user) {
            return ['changed_by_id' => null, 'changed_by_type' => 'system'];
        }

        // The enum accepts customer|employee|system only, so this is a coarse
        // classification of the actor, not a morph class. `changed_by_id` points
        // at users.id in every case — see OrderStatusHistory::changedBy.
        return [
            'changed_by_id' => $user->id,
            'changed_by_type' => $user->employee()->exists() ? 'employee' : 'customer',
        ];
    }

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
        $order->statusHistory()->create($this->causer() + [
            'status' => $order->status,
            'changed_at' => now(),
        ]);

        /*
         * If this number was on an imported contact list, it has just stopped
         * being a supplementary contact and become a customer.
         *
         * Above the manual_entry return on purpose. A recorded past order is
         * still evidence that the person bought from us, and the whole point of
         * the imported/acquired split is that it reflects who has actually
         * ordered — not who ordered through a channel that also sends
         * notifications.
         */
        \DB::afterCommit(fn () => app(\App\Services\Contacts\ContactConverter::class)->convertFromOrderQuietly($order));

        // Past orders (manual entries) should not trigger notifications or broadcasts.
        if ($order->order_source === 'manual_entry') {
            return;
        }

        // Defer notifications until after the DB transaction commits so the
        // payment row is available and we don't send SMS before payment is confirmed.
        \DB::afterCommit(function () use ($order) {
            // First, before any notification work. The board is a live screen
            // somebody is standing in front of, and this used to be dispatched
            // at the bottom of this closure — behind the customer SMS and a
            // notification for every active employee at the branch — so the
            // kitchen learned about an order after the customer did.
            //
            // Guarded, because it is now sent inline rather than queued. As a
            // queued job a Reverb outage failed harmlessly in the worker; sent
            // inline an exception would escape this callback and surface at the
            // till as a failed sale — for an order that has already committed.
            // A cashier would then take it again. The board catching up on its
            // next poll is a far smaller problem than a duplicate order.
            $this->broadcastQuietly($order, 'created');

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
            $order->statusHistory()->create($this->causer() + [
                'status' => $order->status,
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

        // "How was it?", a few hours later.
        //
        // Dispatched from here because this is the proven seam for anything that
        // reacts to an order finishing, but every guard is re-checked inside the
        // job — hours pass, and by then the order may have been cancelled, the
        // customer may have ordered again, or the kill switch may be off. Off by
        // default; see config/order_feedback.php.
        if (in_array($order->status, ['completed', 'delivered'], true) && config('order_feedback.enabled', false)) {
            \DB::afterCommit(function () use ($order) {
                RequestOrderFeedback::dispatch($order->id)
                    ->delay(now()->addHours((int) config('order_feedback.delay_hours', 3)));
            });
        }

        /*
         * Automation rules that were waiting for an order to finish.
         *
         * Evaluated even when the feature is switched off — the evaluator
         * records what would have happened and sends nothing, which is how a
         * rule earns trust before anybody turns it on. Wrapped so that a fault
         * in a marketing rule can never be the reason an order fails to
         * complete.
         */
        if (in_array($order->status, ['completed', 'delivered'], true)) {
            \DB::afterCommit(function () use ($order) {
                try {
                    app(\App\Services\Automation\TriggerEvaluator::class)->evaluate($order);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Automation evaluation failed', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
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
            $this->broadcastQuietly($order, 'updated');
        });
    }

    /**
     * Broadcast the order, and never let a broadcast failure become the
     * caller's problem.
     *
     * `OrderBroadcastEvent` is `ShouldBroadcastNow`, so this runs inline in the
     * request rather than in a worker. That is deliberate — queueing it put the
     * kitchen's copy of an order behind every notification the order generated
     * — but it also means an unreachable Reverb would throw straight into
     * whoever saved the order. Taking money must not depend on a websocket
     * being up, so the failure is logged and swallowed. The boards recover on
     * their own: both poll as a safety net for exactly this.
     */
    protected function broadcastQuietly(Order $order, string $changeType): void
    {
        try {
            OrderBroadcastEvent::dispatch($order, $changeType);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Order broadcast failed', [
                'order_id' => $order->id,
                'change_type' => $changeType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify all active employees at the branch.
     */
    /**
     * Write the branch's in-app "new order" notifications in one query.
     *
     * This used to loop `$employee->user?->notify(...)`. `NewOrderNotification`
     * is `ShouldQueue`, so every employee got their own queued job — nineteen
     * of them at Ashaiman — each serialising the whole Order model, waking a
     * worker, and writing a single row. Around four seconds of worker time per
     * order, to produce nineteen rows.
     *
     * And the rows are identical: `NewOrderNotification::toArray()` describes
     * the order and never looks at the notifiable. So the payload is built once
     * and inserted for everyone at once — same rows, same shape, one query.
     *
     * The notification class stays as the single definition of that payload;
     * only the delivery mechanism changes. It is still used normally elsewhere.
     */
    protected function notifyBranchEmployees(Order $order): void
    {
        $userIds = Employee::whereHas('branches', fn ($q) => $q->where('branches.id', $order->branch_id))
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        // `toArray()` ignores its argument — the payload is about the order, not
        // the recipient — so the order stands in for the notifiable here.
        $payload = json_encode((new NewOrderNotification($order))->toArray($order));
        $now = now();

        \DB::table('notifications')->insert(
            $userIds->map(fn ($userId) => [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => NewOrderNotification::class,
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $userId,
                'data' => $payload,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
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
