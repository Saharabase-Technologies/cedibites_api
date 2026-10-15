<?php

namespace App\Services\Orders;

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Events\OrderBroadcastEvent;
use App\Models\Order;
use App\Models\OrderRound;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CancelRequestedNotification;
use App\Services\Alerts\AdminAlerter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A round cancelled on its own (D40, D45).
 *
 * Staff ask, an admin approves or rejects, and the roles that cancel an order
 * outright cancel a round outright (D39). Once cancelled, the round's price
 * comes off the order, its stock comes back, and its money is put right:
 *
 * - a payment taken with the round is refunded, as an order's is on cancel;
 * - a Pay later round not yet paid leaves the order owing less;
 * - money paid inside a bigger payment shows as owed back to the customer,
 *   and the cashier who hands it over records it (TakePayment::giveBack).
 */
class RoundCancellation
{
    public function __construct(private RecipeDeductionService $stock) {}

    /** Ask for the round's cancel. Null when asked, or why it cannot be. */
    public function request(Order $order, OrderRound $round, User $by, string $reason): ?string
    {
        $refusal = DB::transaction(function () use ($order, $round, $by, $reason) {
            $locked = OrderRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($refusal = $this->refusal($order, $locked)) {
                return $refusal;
            }

            if ($locked->cancel_requested_at !== null) {
                return "A cancel for round {$locked->number} is already waiting.";
            }

            if (! in_array($locked->status, OrderRound::CANCEL_REQUEST_STATUSES, true)) {
                return "Round {$locked->number} is {$locked->status}. Ask for a cancel only before it is ready.";
            }

            $locked->update([
                'cancel_requested_by' => $by->id,
                'cancel_request_reason' => $reason,
                'cancel_requested_at' => now(),
            ]);

            activity('orders')
                ->causedBy($by)
                ->performedOn($order)
                ->withProperties(['round' => $locked->number, 'reason' => $reason])
                ->event('round_cancel_requested')
                ->log("Cancel requested for round {$locked->number} of order {$order->order_number}");

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        $round->refresh();
        $this->tellHeadOffice($order, $round, $by, $reason);
        $this->broadcast($order);

        return null;
    }

    /** The admin says no: the round carries on where it was. */
    public function reject(Order $order, OrderRound $round, User $by): ?string
    {
        $refusal = DB::transaction(function () use ($order, $round, $by) {
            $locked = OrderRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (! $locked->cancelAsked()) {
                return "Round {$locked->number} has no cancel waiting.";
            }

            $locked->update([
                'cancel_requested_by' => null,
                'cancel_request_reason' => null,
                'cancel_requested_at' => null,
            ]);

            activity('orders')
                ->causedBy($by)
                ->performedOn($order)
                ->withProperties(['round' => $locked->number])
                ->event('round_cancel_rejected')
                ->log("Cancel rejected for round {$locked->number} of order {$order->order_number}");

            return null;
        });

        if ($refusal === null) {
            $this->broadcast($order);
        }

        return $refusal;
    }

    /**
     * Cancel the round: approving a request, or outright. Null when done, or
     * why it cannot be.
     */
    public function cancel(Order $order, OrderRound $round, User $by, ?string $reason = null): ?string
    {
        $refusal = DB::transaction(function () use ($order, $round, $by, $reason) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked = OrderRound::whereKey($round->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();

            if ($refusal = $this->refusal($lockedOrder, $locked)) {
                return $refusal;
            }

            if (! $locked->canTransitionTo('cancelled')) {
                return "Round {$locked->number} is {$locked->status}. It can no longer be cancelled.";
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_by' => $by->id,
                'cancelled_reason' => $reason ?? $locked->cancel_request_reason,
                'cancelled_at' => now(),
                // The customer's paper lists this round, so the next receipt
                // is an Updated Receipt without it. Never on paper, nothing
                // printed is wrong, so nothing more is owed because of it.
                'receipt_printed_at' => $locked->receipt_printed_at === null ? now() : null,
            ]);

            $lockedOrder->subtotal = round((float) $lockedOrder->subtotal - (float) $locked->subtotal, 2);
            $lockedOrder->total_amount = round((float) $lockedOrder->total_amount - (float) $locked->total, 2);

            // Money taken with the round goes back with it. Written without
            // the payment's events: the observer would give back the whole
            // order's stock if this left nothing paid, and the rest of the
            // order is still on the table. Only the round's stock comes back.
            Payment::where('order_round_id', $locked->id)
                ->where('payment_status', 'completed')
                ->update(['payment_status' => 'refunded', 'refunded_at' => now()]);

            $lockedOrder->unsetRelation('payments');

            if ($lockedOrder->balance_due <= 0) {
                $lockedOrder->owing_since = null;
                $lockedOrder->fully_paid_at ??= now();
            }

            $lockedOrder->save();

            activity('orders')
                ->causedBy($by)
                ->performedOn($lockedOrder)
                ->withProperties([
                    'round' => $locked->number,
                    'round_total' => (float) $locked->total,
                    'reason' => $locked->cancelled_reason,
                    'to_give_back' => $lockedOrder->to_give_back,
                ])
                ->event('round_cancelled')
                ->log("Round {$locked->number} of order {$lockedOrder->order_number} cancelled");

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        try {
            $this->stock->reverseForRound($round->fresh());
        } catch (\Throwable $e) {
            Log::error('Stock reversal failed for a cancelled round', [
                'order_id' => $order->id,
                'round_id' => $round->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->broadcast($order);

        return null;
    }

    /** What stops any cancel on this round, or null. */
    private function refusal(Order $order, OrderRound $round): ?string
    {
        if ($round->status === 'cancelled') {
            return "Round {$round->number} is already cancelled.";
        }

        if (in_array($order->status, ['cancelled', 'cancel_requested'], true)) {
            return "#{$order->order_number} itself is cancelled or waiting on a cancel.";
        }

        return null;
    }

    /** The same push and text an order's cancel request sends. */
    private function tellHeadOffice(Order $order, OrderRound $round, User $by, string $reason): void
    {
        try {
            $admins = User::permission('access_admin_panel')->get();
            Notification::send($admins, new CancelRequestedNotification($order, $reason, $by->name ?? 'Staff', $round));
        } catch (\Throwable $e) {
            Log::warning('RoundCancellation: push notification dispatch failed', ['error' => $e->getMessage()]);
        }

        try {
            $order->loadMissing('branch');
            app(AdminAlerter::class)->send('cancel_requested', sprintf(
                'CediBites: %s asks to cancel round %d of order #%s at %s (GHS %s): %s. %s',
                $by->name ?? 'Staff',
                $round->number,
                $order->order_number,
                $order->branch?->name ?? 'a branch',
                number_format((float) $round->total, 2),
                Str::limit(rtrim(trim($reason), '.'), 70),
                rtrim((string) config('app.frontend_url'), '/').'/admin/orders/cancel-requests',
            ), ['order_id' => $order->id, 'order_number' => $order->order_number, 'round' => $round->number]);
        } catch (\Throwable $e) {
            Log::warning('RoundCancellation: SMS alert failed', ['error' => $e->getMessage()]);
        }
    }

    /** The order's own status has not moved, so the boards are told here. */
    private function broadcast(Order $order): void
    {
        try {
            OrderBroadcastEvent::dispatch($order->fresh(), 'updated');
        } catch (\Throwable $e) {
            Log::warning('Order broadcast failed for a round cancel', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
