<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelPaymentCheck;
use App\Models\HubtelPaymentNotification;
use App\Models\Order;
use App\Services\HubtelPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Payments a customer makes to a branch by dialling its code, and the till
 * checking that one really arrived.
 *
 * The cashier rings the sale, often as cash, and the customer then pays by
 * *713*1552# or the like. Hubtel posts each payment to us, but the post has
 * no signature, so nothing here trusts it: every one is asked about with
 * Hubtel's status check, and only a payment Hubtel calls Paid reaches the
 * till. A forged post comes back "not found".
 *
 * The status check answers for any of the business's accounts, whichever
 * account's URL it is asked through (proven 2026-10-06 with a Lakeside
 * payment and an Ashaiman one), so the company key checks every branch.
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

        try {
            $answer = $this->hubtel->transactionStatus(['clientReference' => $reference]);
        } catch (\Throwable) {
            return $lastAttempt ? 'not_found' : self::RETRY;
        }

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

    /** Today's branch code payments at this branch, newest first. */
    public function today(int $branchId): Collection
    {
        return HubtelIncomingPayment::where('branch_id', $branchId)
            ->where('paid_at', '>=', now()->startOfDay())
            ->orderByDesc('paid_at')
            ->limit(100)
            ->get();
    }

    /**
     * The cashier typed the transaction ID from the customer's MoMo message.
     * Did that payment happen?
     *
     * A payment already on our list answers from there. Anything else is
     * asked of Hubtel. Every answer is written down, so the same message
     * shown for a second sale says when, where and by whom it was checked
     * first.
     *
     * @return array{outcome: string, amount: ?float, paid_at: ?string, payer_last_four: ?string, branch: ?string, order_number: ?string, first_checked: ?array}
     */
    public function check(string $transactionId, Branch $branch, Employee $employee): array
    {
        $first = HubtelPaymentCheck::with(['branch', 'employee.user'])
            ->where('transaction_id', $transactionId)
            ->whereIn('outcome', ['paid', 'ours'])
            ->oldest('id')
            ->first();

        $answer = $this->ask($transactionId);

        if ($answer['outcome'] === 'unavailable') {
            return $answer + ['first_checked' => null];
        }

        HubtelPaymentCheck::create([
            'branch_id' => $branch->id,
            'employee_id' => $employee->id,
            'transaction_id' => $transactionId,
            'outcome' => $answer['outcome'],
            'amount' => $answer['amount'],
            'paid_at' => $answer['paid_at'],
        ]);

        return $answer + [
            'first_checked' => $first ? [
                'at' => $first->created_at->toIso8601String(),
                'branch' => $first->branch?->name,
                'by' => $first->employee?->user?->name,
            ] : null,
        ];
    }

    /** What we or Hubtel know about one transaction ID. */
    private function ask(string $transactionId): array
    {
        $blank = ['amount' => null, 'paid_at' => null, 'payer_last_four' => null, 'branch' => null, 'order_number' => null];

        $known = HubtelIncomingPayment::with('branch')->where('network_transaction_id', $transactionId)->first();

        if ($known) {
            return [
                'outcome' => 'paid',
                'amount' => (float) $known->amount,
                'paid_at' => $known->paid_at->toIso8601String(),
                'payer_last_four' => $known->payerLastFour(),
                'branch' => $known->branch?->name,
                'order_number' => null,
            ];
        }

        try {
            $answer = $this->hubtel->transactionStatus(['networkTransactionId' => $transactionId]);
        } catch (\Throwable) {
            return ['outcome' => 'unavailable'] + $blank;
        }

        if ($answer['http'] === 404) {
            return ['outcome' => 'not_found'] + $blank;
        }

        if ($answer['http'] !== 200 || $answer['data'] === null) {
            return ['outcome' => 'unavailable'] + $blank;
        }

        $data = $answer['data'];

        if (($data['status'] ?? null) !== 'Paid') {
            return ['outcome' => 'not_paid'] + $blank;
        }

        $reference = (string) ($data['clientReference'] ?? '');
        $ours = $reference !== '' && $this->isOurs($reference);

        return [
            'outcome' => $ours ? 'ours' : 'paid',
            'amount' => (float) ($data['amountAfterCharges'] ?? $data['amount'] ?? 0),
            'paid_at' => Carbon::parse($data['date'] ?? 'now')->toIso8601String(),
            'payer_last_four' => ($number = $this->payerNumber($reference)) ? substr($number, -4) : null,
            'branch' => null,
            'order_number' => $ours ? $this->orderFor($reference) : null,
        ];
    }

    /**
     * A payment we started ourselves: a till prompt or an online checkout.
     * Those already belong to an order and are never listed as branch code.
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

    /** The order a payment we started belongs to, by its session or its number. */
    private function orderFor(string $reference): ?string
    {
        if (Str::isUuid($reference)) {
            $orderId = CheckoutSession::where('session_token', $reference)->value('order_id');

            return $orderId ? Order::whereKey($orderId)->value('order_number') : null;
        }

        return Order::where('order_number', $reference)->value('order_number');
    }
}
