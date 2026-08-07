<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderFeedback;
use App\Notifications\OrderFeedbackRequestNotification;
use App\Services\HubtelSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Ask one customer how their order was.
 *
 * Dispatched with a delay by OrderObserver when an order completes. Every guard
 * is re-checked here rather than at dispatch, because hours pass in between: the
 * order may have been cancelled, the customer may have ordered again, and the
 * kill switch may have been turned off since.
 */
class RequestOrderFeedback implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public int $orderId) {}

    public function handle(HubtelSmsService $sms): void
    {
        if (! config('order_feedback.enabled', false)) {
            return;
        }

        $order = Order::with('customer.user')->find($this->orderId);

        if (! $order || ! $this->shouldAsk($order)) {
            return;
        }

        $phone = $this->phoneFor($order);

        if (! $phone) {
            return;
        }

        if ($this->askedRecently($phone)) {
            // Somebody who buys lunch and dinner is a good customer, not
            // somebody to text twice in a day.
            return;
        }

        // Outside the window, come back in the morning. A 9pm dinner order plus
        // three hours is midnight, and the right gap after a late dinner is
        // breakfast — so this rolls forward rather than being dropped.
        if ($nextMorning = $this->outsideWindow()) {
            self::dispatch($this->orderId)->delay($nextMorning);

            return;
        }

        $feedback = $this->claim($order);

        if (! $feedback) {
            return;
        }

        try {
            $user = $order->customer?->user;

            if ($user) {
                // A registered customer gets it in the in-app feed as well.
                $user->notify(new OrderFeedbackRequestNotification($feedback));
            } else {
                // Most orders are guests, who have no user record to notify.
                // Same text either way — see the notification.
                $sms->sendSingle(
                    $this->toHubtelFormat($phone),
                    OrderFeedbackRequestNotification::message($feedback),
                    'OrderFeedbackRequestNotification',
                );
            }

            $feedback->update(['sent_at' => now()]);
        } catch (\Throwable $e) {
            // The row stays with sent_at null, so it is not counted in the
            // response rate — a request nobody received must not read as a
            // request nobody answered.
            Log::warning('Order feedback request failed to send', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Whether this order is one we ask about at all.
     *
     * Manual entries are excluded to match OrderObserver, which already keeps
     * them out of every other notification — they are historical records typed
     * in after the fact, and texting somebody about a meal from last month is
     * worse than saying nothing.
     */
    private function shouldAsk(Order $order): bool
    {
        return in_array($order->status, ['completed', 'delivered'], true)
            && $order->order_source !== 'manual_entry';
    }

    /**
     * Create the row, or find that somebody already did.
     *
     * The unique index on order_id is the guard, not a prior read: a retried
     * queue job and a duplicated dispatch both land here, and asking first would
     * be a race. A row that already exists means the request has been made.
     */
    private function claim(Order $order): ?OrderFeedback
    {
        try {
            return OrderFeedback::create([
                'order_id' => $order->id,
                'token' => OrderFeedback::generateToken(),
                'expires_at' => now()->addDays((int) config('order_feedback.expires_after_days', 7)),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function phoneFor(Order $order): ?string
    {
        $phone = $order->contact_phone ?: $order->customer?->user?->phone;

        return $phone ? trim($phone) : null;
    }

    /**
     * Whether this number has already been asked today.
     *
     * Matched on the order's contact phone rather than the customer id, because
     * a guest ordering twice has two unrelated order rows and the same handset.
     */
    private function askedRecently(string $phone): bool
    {
        $cap = (int) config('order_feedback.per_customer_daily_cap', 1);

        if ($cap <= 0) {
            return false;
        }

        $recent = OrderFeedback::whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subDay())
            ->whereHas('order', fn ($q) => $q->where('contact_phone', $phone))
            ->count();

        return $recent >= $cap;
    }

    /** The next moment inside the window, or null if we are already in it. */
    private function outsideWindow(): ?\Illuminate\Support\Carbon
    {
        $start = (int) config('order_feedback.send_window.start_hour', 8);
        $end = (int) config('order_feedback.send_window.end_hour', 19);
        $now = now();

        if ($now->hour >= $start && $now->hour < $end) {
            return null;
        }

        // Before opening today, or after closing and therefore tomorrow.
        return $now->hour < $start
            ? $now->copy()->setTime($start, 0)
            : $now->copy()->addDay()->setTime($start, 0);
    }

    /** Hubtel wants 233XXXXXXXXX — no plus, no leading zero. */
    private function toHubtelFormat(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233'.substr($digits, 1);
        }

        return $digits;
    }
}
