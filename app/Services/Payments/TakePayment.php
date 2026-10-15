<?php

namespace App\Services\Payments;

use App\Helpers\PhoneHelper;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Services\HubtelPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money taken on an order that owes (D7).
 *
 * An order has a total and a list of payments, and is paid when the payments
 * cover the total. Pay later starts an order with none. Each part is one
 * payment row, by any method, taken by anyone who can take orders, recorded
 * against whoever took it and the shift they are on (D8, D9).
 *
 * Every part is worked out under a lock on the order, so two tills taking
 * money for one order at the same moment cannot both take the last of it.
 */
class TakePayment
{
    /** Our prompt for a part carries PL-<order>-<attempt>. Hubtel allows 32 characters. */
    public const REFERENCE_PREFIX = 'PL-';

    /**
     * A prompt still waiting holds its amount for this long, so a second
     * prompt does not ask for the same money. Cash and card are not held up
     * by it: the cashier has that money in hand.
     */
    private const PROMPT_HOLD_MINUTES = 10;

    public function __construct(
        private PaymentCollector $collector,
        private HubtelPaymentService $hubtel,
    ) {}

    /**
     * Cash handed over. More than is owed is fine: the part is what was owed,
     * the rest is change, and what was handed over is kept with the part.
     *
     * @return array{payment: Payment, change: float}
     */
    public function cash(Order $order, Employee $by, float $handedOver): array
    {
        return DB::transaction(function () use ($order, $by, $handedOver) {
            $owing = $this->owing($order);
            $part = round(min($handedOver, $owing), 2);

            $payment = Payment::create($this->stamp($order, $by) + [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => 'cash',
                'payment_status' => 'completed',
                'amount' => $part,
                'amount_tendered' => $handedOver > $part ? round($handedOver, 2) : null,
                'paid_at' => now(),
            ]);

            return ['payment' => $payment, 'change' => round($handedOver - $part, 2)];
        });
    }

    /** Card, up to what is owed. */
    public function card(Order $order, Employee $by, float $amount): Payment
    {
        return DB::transaction(function () use ($order, $by, $amount) {
            $this->refuseOver($order, $amount, $this->owing($order));

            return Payment::create($this->stamp($order, $by) + [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => 'card',
                'payment_status' => 'completed',
                'amount' => round($amount, 2),
                'paid_at' => now(),
            ]);
        });
    }

    /**
     * A payment the customer made by dialling the branch code, which Hubtel
     * confirmed, linked to this order (D14). The incoming payment is locked,
     * so two tills cannot link it twice; the unique MTN ID on payments is the
     * last guard. A shared one becomes this branch's.
     */
    public function branchCode(Order $order, Employee $by, int $incomingId): Payment
    {
        return DB::transaction(function () use ($order, $by, $incomingId) {
            $owing = $this->owing($order);
            $incoming = HubtelIncomingPayment::with('branch')->whereKey($incomingId)->lockForUpdate()->first();

            if (! $incoming) {
                throw new TakePaymentRefused('That payment was not found. Refresh the list.', 404);
            }

            if ($incoming->payment_id !== null) {
                throw new TakePaymentRefused('That payment is already on an order.', 409);
            }

            if ($incoming->branch_id !== null && $incoming->branch_id !== $order->branch_id) {
                throw new TakePaymentRefused("That payment is on {$incoming->branch?->name}'s till.", 409);
            }

            if ((float) $incoming->amount > $owing + 0.004) {
                throw new TakePaymentRefused(sprintf(
                    'This payment is GHS %s and %s owes GHS %s.',
                    number_format((float) $incoming->amount, 2), $order->order_number, number_format($owing, 2),
                ));
            }

            try {
                $payment = Payment::create($this->stamp($order, $by, PaymentCollector::SOURCE_BRANCH_CODE_LINK) + [
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    // Proven by Hubtel, like a prompt.
                    'payment_method' => 'mobile_money',
                    'payment_status' => 'completed',
                    'amount' => (float) $incoming->amount,
                    'transaction_id' => $incoming->network_transaction_id,
                    // When the customer paid, not when it was linked.
                    'paid_at' => $incoming->paid_at,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new TakePaymentRefused('That payment is already on an order.', 409);
            }

            $incoming->forceFill([
                'payment_id' => $payment->id,
                'branch_id' => $order->branch_id,
                'routed_by' => $incoming->routed_by ?? BranchCodeRouter::BY_CASHIER,
                'routed_at' => $incoming->routed_at ?? now(),
                'taken_by' => $incoming->taken_by ?? $by->id,
            ])->save();

            return $payment;
        });
    }

    /**
     * A MoMo prompt for a part, to any number the cashier types (D7). The part
     * waits as pending until Hubtel answers. Each attempt has a reference of
     * its own, so the answer finds the right part (T5).
     */
    public function momoPrompt(Order $order, Employee $by, string $number, float $amount): Payment
    {
        $payment = DB::transaction(function () use ($order, $by, $amount) {
            $this->refuseOver($order, $amount, $this->owing($order, holdPrompts: true));

            $attempt = Payment::withTrashed()->where('order_id', $order->id)->whereNotNull('reference')->count() + 1;

            return Payment::create($this->stamp($order, $by) + [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => 'mobile_money',
                'payment_status' => 'pending',
                'amount' => round($amount, 2),
                'reference' => self::REFERENCE_PREFIX.$order->order_number.'-'.$attempt,
            ]);
        });

        $branch = Branch::find($order->branch_id);

        try {
            $hubtel = $this->hubtel->forBranch($branch);
            $result = $hubtel->initializeReceiveMoney([
                'order' => (object) [
                    'id' => $order->id,
                    'order_number' => $payment->reference,
                    'total_amount' => $payment->amount,
                    'delivery_fee' => 0,
                    'customer_id' => $order->customer_id,
                    'contact_name' => $order->contact_name,
                    'contact_phone' => PhoneHelper::toInternational($number),
                ],
                'description' => "Order {$order->order_number} - {$branch?->name}",
                'customer_name' => $order->contact_name,
                'customer_phone' => PhoneHelper::toLocal($number),
            ]);
        } catch (\Throwable $e) {
            // The prompt never reached Hubtel, so nobody was asked for money.
            // Written without model events: there is no failed payment to
            // tell the customer or the manager about.
            Payment::whereKey($payment->id)->update(['payment_status' => 'failed']);

            if ($e instanceof \InvalidArgumentException) {
                throw new TakePaymentRefused($e->getMessage());
            }

            Log::warning('A MoMo prompt for a part could not be sent', [
                'order_id' => $order->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage(),
            ]);

            throw new TakePaymentRefused('The MoMo prompt could not be sent. Try again, or take the money another way.', 502);
        }

        $payment->update([
            'transaction_id' => $result['transactionId'] ?? null,
            'hubtel_account_number' => $hubtel->accountNumber(),
            'payment_gateway_response' => $result,
        ]);

        return $payment->fresh();
    }

    /**
     * Hubtel's answer for a part's prompt, from its callback or a status
     * check. A part already settled is left as it is, so a second answer
     * changes nothing.
     */
    public function settlePrompt(string $reference, bool $paid, ?string $transactionId = null, array $answer = []): ?Payment
    {
        $payment = Payment::where('reference', $reference)->first();

        if (! $payment) {
            Log::warning('Hubtel answered for a part we have no record of', ['reference' => $reference]);

            return null;
        }

        if ($payment->payment_status !== 'pending') {
            return $payment;
        }

        if (! $paid) {
            // Declined or lapsed at the counter, with the cashier watching the
            // till. Written without model events: no "payment failed" text to a
            // customer standing there, and no message to the manager.
            Payment::whereKey($payment->id)->where('payment_status', 'pending')->update([
                'payment_status' => 'failed',
                'payment_gateway_response' => json_encode(array_merge($payment->payment_gateway_response ?? [], ['answer' => $answer])),
            ]);

            return $payment->fresh();
        }

        $payment->update([
            'payment_status' => 'completed',
            'paid_at' => now(),
            'transaction_id' => $payment->transaction_id ?? $transactionId,
            'payment_gateway_response' => array_merge($payment->payment_gateway_response ?? [], ['answer' => $answer]),
        ]);

        $order = Order::with('payments')->find($payment->order_id);

        if ($order && $order->amount_paid > $order->goods_amount + 0.004) {
            // The money arrived, so it is recorded. Somebody took the rest
            // another way while the prompt waited. It needs giving back.
            Log::warning('A part paid more than the order owed', [
                'order_id' => $order->id,
                'reference' => $reference,
                'over_by' => round($order->amount_paid - $order->goods_amount, 2),
            ]);
        }

        return $payment;
    }

    /**
     * Cash handed back to the customer from the till, when a cancelled round
     * left more paid than the order now costs (D45).
     *
     * Written as a negative cash payment against whoever hands it over, on
     * their shift, so their cash count is held to it. The payment it came out
     * of stays on record as it was. Without the payment's events: it is not a
     * sale, so it puts nothing on a shift and takes no stock.
     */
    public function giveBack(Order $order, Employee $by): Payment
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = Order::with('payments')->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked || $locked->to_give_back <= 0) {
                throw new TakePaymentRefused("Nothing is owed back on {$order->order_number}.", 409);
            }

            $amount = $locked->to_give_back;

            return Payment::withoutEvents(fn () => Payment::create($this->stamp($order, $by, PaymentCollector::SOURCE_CASH_BACK) + [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => 'cash',
                'payment_status' => 'completed',
                'amount' => -$amount,
                'paid_at' => now(),
            ]));
        });
    }

    /** Ask Hubtel about a prompt that is still waiting, in case its callback was lost. */
    public function checkPrompt(Payment $payment): Payment
    {
        if ($payment->payment_status !== 'pending' || ! $payment->reference) {
            return $payment;
        }

        $answer = $this->hubtel->transactionStatus(['clientReference' => $payment->reference]);
        $status = $answer['data']['status'] ?? null;

        return match ($status) {
            'Paid' => $this->settlePrompt($payment->reference, true, $answer['data']['externalTransactionId'] ?? null, $answer['data']),
            'Refunded' => $this->settlePrompt($payment->reference, false, null, $answer['data']),
            default => $payment,
        };
    }

    /**
     * What the order still owes, read under a lock on the order. A cancelled
     * order owes nothing and takes nothing. With $holdPrompts, prompts still
     * waiting count as if paid.
     */
    private function owing(Order $order, bool $holdPrompts = false): float
    {
        $locked = Order::whereKey($order->id)->lockForUpdate()->first();

        if (! $locked || $locked->status === 'cancelled') {
            throw new TakePaymentRefused("{$order->order_number} is cancelled. It takes no payment.", 409);
        }

        $owing = $locked->balance_due;

        if ($holdPrompts) {
            $owing -= (float) Payment::where('order_id', $order->id)
                ->where('payment_status', 'pending')
                ->whereNotNull('reference')
                ->where('created_at', '>=', now()->subMinutes(self::PROMPT_HOLD_MINUTES))
                ->sum('amount');
        }

        $owing = round(max(0.0, $owing), 2);

        if ($owing <= 0) {
            throw new TakePaymentRefused($holdPrompts && $locked->balance_due > 0
                ? "A MoMo prompt for what {$order->order_number} owes is still waiting. Wait for it, or take the money another way."
                : "{$order->order_number} is already paid.", 409);
        }

        return $owing;
    }

    private function refuseOver(Order $order, float $amount, float $owing): void
    {
        if ($amount > $owing + 0.004) {
            throw new TakePaymentRefused(sprintf('%s owes GHS %s. Take no more than that.', $order->order_number, number_format($owing, 2)));
        }
    }

    /** Who took it and on which shift, and why (D9). */
    private function stamp(Order $order, Employee $by, ?string $source = null): array
    {
        return $this->collector->stamp(
            $by->id,
            $order->branch_id,
            $source ?? ($order->paid_later ? PaymentCollector::SOURCE_PAY_LATER : PaymentCollector::SOURCE_SPLIT),
        );
    }
}
