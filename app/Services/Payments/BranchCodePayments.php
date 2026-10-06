<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelPaymentNotification;
use App\Models\Order;
use App\Services\HubtelPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Payments a customer makes to a branch by dialling its code, and the till
 * using one to settle a sale.
 *
 * Hubtel posts every payment into a branch's account to us, but the post has
 * no signature, so nothing here trusts it. Each one is asked about with
 * Hubtel's status check, using the key for that account, and only a payment
 * Hubtel calls Paid reaches the till. A forged post comes back "not found".
 */
class BranchCodePayments
{
    /** Hubtel has not answered yet, or answered "not found" too soon. Ask again later. */
    public const RETRY = 'retry';

    public function __construct(private HubtelPaymentService $hubtel) {}

    /**
     * Ask Hubtel about one post and keep the payment if it is real.
     *
     * Returns what became of it, or RETRY. On the last attempt a payment
     * Hubtel still cannot find is given up as not_found.
     */
    public function verify(HubtelPaymentNotification $notification, bool $lastAttempt = false): string
    {
        $payload = $notification->payload ?? [];
        $data = $payload['Data'] ?? [];

        if (($payload['ResponseCode'] ?? null) !== '0000') {
            return 'failed';
        }

        $reference = trim((string) ($data['ClientReference'] ?? ''));

        if ($reference === '') {
            return 'unreadable';
        }

        if ($this->isOurs($reference)) {
            return 'ours';
        }

        if (HubtelIncomingPayment::where('client_reference', $reference)->exists()) {
            return 'duplicate';
        }

        $gateway = $this->hubtel->forAccountIfKnown($notification->account_number);

        if (! $gateway) {
            return 'no_key';
        }

        $answer = $gateway->transactionStatus(['clientReference' => $reference]);
        $status = $answer['data']['status'] ?? null;

        if ($answer['http'] !== 200 || $status === null) {
            return $lastAttempt ? 'not_found' : self::RETRY;
        }

        if ($status !== 'Paid') {
            return $lastAttempt || $status === 'Refunded' ? 'not_paid' : self::RETRY;
        }

        $checked = $answer['data'];
        $branchId = $notification->branch_id
            ?? Branch::where('hubtel_account_number', $notification->account_number)->value('id');

        try {
            HubtelIncomingPayment::create([
                'branch_id' => $branchId,
                'account_number' => $notification->account_number,
                'client_reference' => $reference,
                'network_transaction_id' => $checked['externalTransactionId'] ?? $data['ExternalTransactionId'] ?? null,
                'hubtel_transaction_id' => $checked['transactionId'] ?? null,
                'payer_number' => $this->payerNumber($reference),
                // Hubtel's figures, not the post's.
                'amount' => $checked['amountAfterCharges'] ?? $checked['amount'],
                'amount_charged' => $checked['amount'] ?? null,
                'paid_at' => Carbon::parse($checked['date'] ?? $data['PaymentDate'] ?? 'now'),
                'verified_at' => now(),
                'notification_id' => $notification->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Hubtel posted twice and both were checked at once.
            return 'duplicate';
        }

        return 'paid';
    }

    /**
     * A payment we started ourselves: a till prompt or an online checkout.
     * Those already belong to an order and must never be offered again.
     */
    public function isOurs(string $reference): bool
    {
        if (str_starts_with($reference, 'cb-key-check-')) {
            return true;
        }

        // session_token is a uuid column on Postgres, and comparing it with
        // Hubtel's own reference is an error there, not a false. SQLite, which
        // the tests run on, lets it through, so the guard has to be explicit.
        if (Str::isUuid($reference)) {
            return CheckoutSession::where('session_token', $reference)->exists();
        }

        // Early sessions sent the token cut to 32 characters.
        if (preg_match('/^[0-9a-f-]{32,35}$/i', $reference)
            && CheckoutSession::whereRaw('CAST(session_token AS TEXT) LIKE ?', [strtolower($reference).'%'])->exists()) {
            return true;
        }

        return Order::where('order_number', $reference)->exists();
    }

    /**
     * The customer's number, which Hubtel writes inside its own reference:
     * NHbbe724b267c14ff2a20bcc76775e6b6b_233247879103_15642.
     */
    public function payerNumber(string $reference): ?string
    {
        return preg_match('/_(233\d{9})_/', $reference, $m) ? $m[1] : null;
    }

    /** Today's payments at this branch that no sale has used yet, newest first. */
    public function unusedToday(int $branchId): Collection
    {
        return HubtelIncomingPayment::where('branch_id', $branchId)
            ->whereNull('order_id')
            ->where('paid_at', '>=', now()->startOfDay())
            ->orderByDesc('paid_at')
            ->limit(50)
            ->get();
    }

    /**
     * Why this payment cannot settle this sale, or null if it can.
     *
     * The amount has to match to the pesewa. A customer who paid a different
     * figure is a conversation for the cashier, not something to round away.
     */
    public function refusal(?HubtelIncomingPayment $payment, int $branchId, float $total): ?string
    {
        if (! $payment) {
            return 'That payment is not on the list any more. Refresh it and pick again.';
        }

        if ($payment->order_id) {
            $number = Order::whereKey($payment->order_id)->value('order_number');

            return "That payment is already on order {$number}.";
        }

        if ((int) $payment->branch_id !== $branchId) {
            $name = Branch::whereKey($payment->branch_id)->value('name') ?? 'another branch';

            return "That payment was made to {$name}, not this branch.";
        }

        if ((int) round((float) $payment->amount * 100) !== (int) round($total * 100)) {
            return sprintf('That payment is GHS %.2f. This sale is GHS %.2f.', (float) $payment->amount, $total);
        }

        return null;
    }

    /** The payment now belongs to this order. Called inside the sale's transaction. */
    public function claim(HubtelIncomingPayment $payment, Order $order, Employee $employee): void
    {
        $payment->forceFill([
            'order_id' => $order->id,
            'claimed_by' => $employee->id,
            'claimed_at' => now(),
        ])->save();
    }
}
