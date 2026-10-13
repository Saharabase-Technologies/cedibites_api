<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelUssdFulfilment;
use App\Models\HubtelUssdSession;
use App\Services\HubtelPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Our own screens on the branch codes, through Hubtel's Programmable Services.
 *
 * A customer at Lakeside dials *713*1552*50#. Hubtel posts the dial to us and
 * we answer with one screen: "Pay GHS 50.00 to CediBites Lakeside", 1 to pay,
 * 2 to cancel. On 1 we hand Hubtel the amount, and the next thing on the
 * phone is the prompt for the PIN. A dial with no amount is asked for it
 * first, and a dial with no branch is asked that before anything.
 *
 * Hubtel sets a service up for one branch, so each branch's service has its
 * own address, ending in the branch's code. That, and the code in the dial
 * itself, tell us the branch. It is what Hubtel's own payment notification
 * never does.
 *
 * When the dial is paid, Hubtel posts a fulfilment. Like every post from
 * Hubtel it has no signature, so the status check decides, and only a payment
 * Hubtel calls Paid reaches the branch's till. We then tell Hubtel we have
 * it, which Hubtel wants within the hour.
 */
class UssdPayments
{
    /** Hubtel has not answered yet, or answered "not found" too soon. Ask again later. */
    public const RETRY = 'retry';

    private const MAX_BAD_TRIES = 3;

    public function __construct(private HubtelPaymentService $hubtel) {}

    /**
     * The next screen for one post from Hubtel.
     *
     * @param  array<string, mixed>  $post
     * @param  string|null  $routedCode  the branch code at the end of the address Hubtel posted to
     * @return array<string, mixed>
     */
    public function answer(array $post, ?string $routedCode = null): array
    {
        $sessionId = $this->text($post, 'SessionId', 64);
        $type = strtolower($this->text($post, 'Type', 20));

        if ($sessionId === '') {
            return $this->end('', 'We could not start this payment. Please dial again.');
        }

        $session = HubtelUssdSession::where('session_id', $sessionId)->first();

        if ($type === 'timeout') {
            $answer = $this->end($sessionId, 'Payment cancelled.');

            if ($session && in_array($session->state, HubtelUssdSession::WAITING, true)) {
                $session->state = HubtelUssdSession::CANCELLED;
            }
        } elseif ($type === 'initiation') {
            $session ??= new HubtelUssdSession(['session_id' => $sessionId]);
            $answer = $this->start($session, $post, $routedCode);
        } elseif (! $session) {
            return $this->end($sessionId, 'This session has ended. Please dial again.');
        } else {
            $answer = $this->reply($session, $this->text($post, 'Message', 120));
        }

        if ($session) {
            $session->remember($this->kept($post), $answer);
            $session->save();
        }

        return $answer;
    }

    /**
     * What the phone shows when something broke on our side. Hubtel shows its
     * own error for anything that is not one of its answer shapes, so even a
     * failure answers in shape.
     *
     * @return array<string, mixed>
     */
    public function apology(string $sessionId): array
    {
        return $this->end($sessionId, 'Something went wrong on our side. Please dial again or pay at the counter.');
    }

    /**
     * Ask Hubtel about one fulfilment and put the payment on its branch's
     * till if it is real.
     *
     * Returns what became of it, or RETRY. On the last attempt a payment
     * Hubtel still cannot find is given up as not_found.
     */
    public function verify(HubtelUssdFulfilment $fulfilment, bool $lastAttempt = false): string
    {
        $payload = $fulfilment->payload ?? [];
        $info = is_array($payload['OrderInfo'] ?? null) ? $payload['OrderInfo'] : [];
        $sessionId = $this->text($payload, 'SessionId', 64);

        if ($sessionId === '') {
            return 'unreadable';
        }

        $session = HubtelUssdSession::where('session_id', $sessionId)->first();

        if (! $session) {
            return 'unknown_session';
        }

        $status = strtolower($this->text($info, 'Status', 20));

        if (($status !== '' && $status !== 'paid') || ($info['Payment']['IsSuccessful'] ?? null) === false) {
            return 'failed';
        }

        $orderId = $this->text($payload, 'OrderId', 64) ?: null;
        $checked = $this->ask($sessionId, $orderId);

        if ($checked === null) {
            return $lastAttempt ? 'not_found' : self::RETRY;
        }

        if ($checked['status'] !== 'Paid') {
            return $lastAttempt || $checked['status'] === 'Refunded' ? 'not_paid' : self::RETRY;
        }

        if (! $this->matches($session, $checked)) {
            Log::warning('A USSD fulfilment is for a different amount than the dial asked for', [
                'session' => $session->id,
                'asked' => (float) $session->amount,
                'paid' => $checked['amount'] ?? null,
                'after_charges' => $checked['amountAfterCharges'] ?? null,
            ]);

            return 'mismatch';
        }

        if ($orderId) {
            $session->hubtel_order_id = $orderId;
        }

        return $this->credit($session, $checked, [
            'payer_name' => $this->text($info, 'CustomerName', 120) ?: null,
            'payer_number' => $this->text($info, 'CustomerMobileNumber', 20) ?: null,
        ]);
    }

    /**
     * Put a paid dial on its branch's list, once.
     *
     * Hubtel may tell us about one payment twice: as the fulfilment and as
     * the business-wide payment notification, in either order and under
     * either of two references. Whichever comes first lists it. The second
     * finds it there, and gives it the branch if the first could not.
     *
     * @param  array<string, mixed>  $checked  the status check's `data`
     * @param  array{payer_name?: ?string, payer_number?: ?string, notification_id?: ?int}  $known
     */
    public function credit(HubtelUssdSession $session, array $checked, array $known = []): string
    {
        $networkId = $checked['externalTransactionId'] ?? null;

        $listed = HubtelIncomingPayment::where('client_reference', $session->session_id)
            ->when($networkId, fn ($query) => $query->orWhere('network_transaction_id', $networkId))
            ->first();

        $session->state = HubtelUssdSession::PAID;
        $session->save();

        if ($listed) {
            $listed->fill([
                'branch_id' => $session->branch_id,
                'ussd_session_id' => $session->id,
                'payer_name' => $listed->payer_name ?? ($known['payer_name'] ?? null),
            ])->save();

            return 'duplicate';
        }

        try {
            HubtelIncomingPayment::create([
                'branch_id' => $session->branch_id,
                'ussd_session_id' => $session->id,
                'client_reference' => $session->session_id,
                'network_transaction_id' => $networkId,
                'hubtel_transaction_id' => $checked['transactionId'] ?? null,
                // The number that dialled, which we heard first-hand.
                'payer_number' => $session->mobile ?: ($known['payer_number'] ?? null),
                'payer_name' => $known['payer_name'] ?? null,
                // Hubtel's figures, not the post's.
                'amount' => $checked['amountAfterCharges'] ?? $checked['amount'],
                'amount_charged' => $checked['amount'] ?? null,
                'paid_at' => Carbon::parse($checked['date'] ?? 'now'),
                'verified_at' => now(),
                'notification_id' => $known['notification_id'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        return 'paid';
    }

    /**
     * Tell Hubtel the paid dial has reached us. Hubtel wants this within an
     * hour of its fulfilment, and takes it from whitelisted addresses only.
     * Whatever Hubtel says back is kept on the session.
     */
    public function acknowledge(HubtelUssdSession $session): bool
    {
        try {
            $response = Http::acceptJson()->timeout(15)->post(config('services.hubtel.ussd.callback_url'), [
                'SessionId' => $session->session_id,
                'OrderId' => $session->hubtel_order_id,
                'ServiceStatus' => 'success',
                'MetaData' => null,
            ]);

            $taken = $response->successful();
            $note = 'HTTP '.$response->status().' '.trim($response->body());
        } catch (\Throwable $e) {
            $taken = false;
            $note = 'No answer: '.$e->getMessage();
        }

        $session->forceFill([
            'acked_at' => $taken ? now() : null,
            'ack_note' => Str::limit($note, 250, ''),
        ])->save();

        return $taken;
    }

    /**
     * The first post of a dial.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function start(HubtelUssdSession $session, array $post, ?string $routedCode): array
    {
        // Hubtel posting the same dial again must not undo a payment in hand.
        if ($session->state === HubtelUssdSession::SENT_TO_PAY) {
            return $this->handOver($session);
        }

        if ($session->state === HubtelUssdSession::PAID) {
            return $this->end($session->session_id, 'This payment is done. Dial again to make another.');
        }

        $dialled = $this->text($post, 'Message', 120);
        $serviceCode = $this->text($post, 'ServiceCode', 40);
        $branches = $this->branches();

        $routed = $routedCode ? $branches->firstWhere('hubtel_ussd_code', $routedCode) : null;

        [$branch, $after] = $this->readDial($dialled, $serviceCode, $branches, $routed);

        $session->fill([
            'branch_id' => $branch?->id,
            'mobile' => $this->text($post, 'Mobile', 20) ?: null,
            'operator' => $this->text($post, 'Operator', 20) ?: null,
            'platform' => $this->text($post, 'Platform', 20) ?: null,
            'service_code' => $serviceCode ?: null,
            'dialled' => $dialled ?: null,
            'amount' => null,
            'amount_from' => null,
            'bad_tries' => 0,
        ]);

        if ($branches->isEmpty()) {
            $session->state = HubtelUssdSession::REFUSED;

            return $this->end($session->session_id, 'This code is not ready yet. Please pay at the counter.');
        }

        if (! $branch) {
            return $this->askBranch($session, $branches);
        }

        $session->setRelation('branch', $branch);

        if (($after[0] ?? '') === '') {
            return $this->askAmount($session);
        }

        return $this->take($session, $after[0], 'dial');
    }

    /**
     * What the customer typed on a screen of ours.
     *
     * @return array<string, mixed>
     */
    private function reply(HubtelUssdSession $session, string $typed): array
    {
        return match ($session->state) {
            HubtelUssdSession::ASKED_BRANCH => $this->chooseBranch($session, $typed),
            HubtelUssdSession::ASKED_AMOUNT => $this->take($session, $typed, 'typed'),
            HubtelUssdSession::ASKED_CONFIRM => $this->confirm($session, $typed),
            HubtelUssdSession::SENT_TO_PAY => $this->handOver($session),
            default => $this->end($session->session_id, 'This session has ended. Please dial again.'),
        };
    }

    /**
     * The branch a dial was for, and whatever was dialled after its code.
     *
     * Hubtel does not document where a longer dial ends up. It may leave the
     * whole string in Message (*713*1552*50#), or keep the code in
     * ServiceCode (713*1552) and pass only what followed (50). Both are read
     * here, so the answer does not depend on which Hubtel does.
     *
     * The address Hubtel posted to names the branch outright when it ends in
     * a code, and wins: it is the branch Hubtel holds the service under.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array{0: ?Branch, 1: list<string>}
     */
    private function readDial(string $dialled, string $serviceCode, Collection $branches, ?Branch $routed): array
    {
        $byCode = $branches->keyBy('hubtel_ussd_code');
        $parts = $this->parts($dialled);
        $service = $this->parts($serviceCode);

        foreach ($parts as $at => $part) {
            if ($byCode->has($part)) {
                return [$routed ?? $byCode->get($part), array_slice($parts, $at + 1)];
            }
        }

        $named = $routed ?? collect($service)->map(fn (string $part) => $byCode->get($part))->filter()->first();

        if (! $named) {
            return [null, []];
        }

        while ($parts && $service && $parts[0] === $service[0]) {
            array_shift($parts);
            array_shift($service);
        }

        return [$named, $parts];
    }

    /**
     * An amount came in, by the dial or typed. Read it back, or ask again.
     *
     * @return array<string, mixed>
     */
    private function take(HubtelUssdSession $session, string $typed, string $from): array
    {
        // A dial string cannot carry a point, so a dialled amount is whole cedis.
        $amount = $this->amount($typed, wholeCedisOnly: $from === 'dial');
        $floor = (float) config('services.hubtel.ussd.min_amount', 1);
        $ceiling = (float) config('services.hubtel.ussd.max_amount', 5000);

        $problem = match (true) {
            $amount === null => 'That is not an amount.',
            $amount < $floor || $amount > $ceiling => 'One payment is GHS '.$this->plain($floor).' to GHS '.$this->plain($ceiling).'.',
            default => null,
        };

        if ($problem === null) {
            $session->fill(['amount' => $amount, 'amount_from' => $from, 'bad_tries' => 0]);

            return $this->askConfirm($session);
        }

        // A bad amount in the dial has used no try: nothing had been asked yet.
        if ($from === 'typed') {
            $session->bad_tries++;
        }

        if ($session->bad_tries >= self::MAX_BAD_TRIES) {
            $session->state = HubtelUssdSession::REFUSED;

            return $this->end($session->session_id, 'No amount received. Please dial again.');
        }

        return $this->askAmount($session, $problem);
    }

    /**
     * The customer answered "Pay GHS 50.00 to CediBites Lakeside". Only a 1
     * sends it to pay.
     *
     * @return array<string, mixed>
     */
    private function confirm(HubtelUssdSession $session, string $typed): array
    {
        if ($typed === '1') {
            $session->state = HubtelUssdSession::SENT_TO_PAY;

            return $this->handOver($session);
        }

        if ($typed === '2') {
            $session->state = HubtelUssdSession::CANCELLED;

            return $this->end($session->session_id, 'Payment cancelled.');
        }

        $session->bad_tries++;

        if ($session->bad_tries >= self::MAX_BAD_TRIES) {
            $session->state = HubtelUssdSession::REFUSED;

            return $this->end($session->session_id, 'Nothing was paid. Please dial again.');
        }

        return $this->askConfirm($session, 'Press 1 or 2.');
    }

    /**
     * @return array<string, mixed>
     */
    private function chooseBranch(HubtelUssdSession $session, string $typed): array
    {
        $branches = $this->branches();
        $branch = ctype_digit($typed) ? $branches->get((int) $typed - 1) : null;

        if (! $branch) {
            $session->bad_tries++;

            if ($session->bad_tries >= self::MAX_BAD_TRIES) {
                $session->state = HubtelUssdSession::REFUSED;

                return $this->end($session->session_id, 'No branch chosen. Please dial again.');
            }

            return $this->askBranch($session, $branches, 'That is not on the list.');
        }

        $session->fill(['branch_id' => $branch->id, 'bad_tries' => 0]);
        $session->setRelation('branch', $branch);

        return $this->askAmount($session);
    }

    /**
     * The hand-over to Hubtel: charge this amount. Hubtel sends the prompt
     * for the PIN, and from here the screens are Hubtel's and the network's.
     *
     * @return array<string, mixed>
     */
    private function handOver(HubtelUssdSession $session): array
    {
        $line = $this->payLine($session);

        return [
            'SessionId' => $session->session_id,
            'Type' => 'AddToCart',
            'Message' => $line.".\nApprove the MoMo prompt with your PIN.",
            'Item' => ['ItemName' => $this->payee($session), 'Qty' => 1, 'Price' => (float) $session->amount],
            'Label' => $line,
            'DataType' => 'display',
            'FieldType' => 'text',
        ];
    }

    /**
     * The one screen every payment passes: the amount and who is paid, read
     * back before any money moves.
     *
     * @return array<string, mixed>
     */
    private function askConfirm(HubtelUssdSession $session, ?string $problem = null): array
    {
        $session->state = HubtelUssdSession::ASKED_CONFIRM;

        $line = $this->payLine($session);

        return $this->question(
            $session,
            ($problem ? $problem."\n" : '').$line."\n1. Pay\n2. Cancel",
            $line,
            'number',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function askAmount(HubtelUssdSession $session, ?string $problem = null): array
    {
        $session->state = HubtelUssdSession::ASKED_AMOUNT;

        return $this->question(
            $session,
            ($problem ?? $this->payee($session))."\nEnter amount to pay (GHS)",
            'Amount to pay',
            'decimal',
        );
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function askBranch(HubtelUssdSession $session, Collection $branches, ?string $problem = null): array
    {
        $session->state = HubtelUssdSession::ASKED_BRANCH;

        $list = $branches->map(fn (Branch $branch, int $at) => ($at + 1).'. '.Str::ascii($branch->name))->implode("\n");

        return $this->question(
            $session,
            ($problem ?? 'CediBites')."\nChoose your branch\n".$list,
            'Choose your branch',
            'number',
        );
    }

    /**
     * A screen that waits for the customer to type.
     *
     * @return array<string, mixed>
     */
    private function question(HubtelUssdSession $session, string $message, string $label, string $fieldType): array
    {
        return [
            'SessionId' => $session->session_id,
            'Type' => 'response',
            'Message' => $message,
            'Label' => $label,
            'ClientState' => $session->state,
            'DataType' => 'input',
            'FieldType' => $fieldType,
        ];
    }

    /**
     * A last screen. The session is over.
     *
     * @return array<string, mixed>
     */
    private function end(string $sessionId, string $message): array
    {
        return [
            'SessionId' => $sessionId,
            'Type' => 'release',
            'Message' => $message,
            'Label' => $message,
            'DataType' => 'display',
            'FieldType' => 'text',
        ];
    }

    /**
     * Hubtel's status check for a paid dial: by the session ID, which the
     * docs name as the reference, then by Hubtel's own order ID.
     *
     * @return array<string, mixed>|null the `data` block, or null when Hubtel has no answer yet
     */
    private function ask(string $sessionId, ?string $orderId): ?array
    {
        $queries = [['clientReference' => $sessionId]];

        if ($orderId) {
            $queries[] = ['hubtelTransactionId' => $orderId];
        }

        foreach ($queries as $query) {
            try {
                $answer = $this->hubtel->transactionStatus($query);
            } catch (\Throwable) {
                return null;
            }

            if ($answer['http'] === 200 && isset($answer['data']['status'])) {
                return $answer['data'];
            }
        }

        return null;
    }

    /**
     * Is the payment Hubtel found the one this dial asked for? The customer
     * pays Hubtel's fee on top, so the dial's amount is what is left after
     * charges; were the fee ever taken from us instead, it is the gross.
     *
     * @param  array<string, mixed>  $checked
     */
    private function matches(HubtelUssdSession $session, array $checked): bool
    {
        $asked = (float) $session->amount;

        foreach (['amountAfterCharges', 'amount'] as $figure) {
            if (isset($checked[$figure]) && abs((float) $checked[$figure] - $asked) < 0.005) {
                return true;
            }
        }

        return false;
    }

    /** "Pay GHS 50.00 to CediBites Lakeside", as the confirm screen and the hand-over both say it. */
    private function payLine(HubtelUssdSession $session): string
    {
        return 'Pay GHS '.number_format((float) $session->amount, 2).' to '.$this->payee($session);
    }

    /** The name the customer is paying, in letters a USSD screen can show. */
    private function payee(HubtelUssdSession $session): string
    {
        return trim('CediBites '.Str::ascii((string) $session->branch?->name));
    }

    /**
     * Branches a dial can pay, in the order the branch screen lists them.
     *
     * @return Collection<int, Branch>
     */
    private function branches(): Collection
    {
        return Branch::whereNotNull('hubtel_ussd_code')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'hubtel_ussd_code'])
            ->values();
    }

    /**
     * The numbers in a dial: "*713*1552*50#" is 713, 1552, 50.
     *
     * @return list<string>
     */
    private function parts(string $dial): array
    {
        return array_values(array_filter(
            preg_split('/[*#\s]+/', $dial) ?: [],
            fn (string $part) => $part !== '',
        ));
    }

    private function amount(string $typed, bool $wholeCedisOnly): ?float
    {
        $clean = str_replace([',', ' '], '', $typed);
        $shape = $wholeCedisOnly ? '/^\d{1,7}$/' : '/^\d{1,7}(\.\d{1,2})?$/';

        return preg_match($shape, $clean) ? round((float) $clean, 2) : null;
    }

    /** 1 and 5,000 rather than 1.00 and 5,000.00; 0.5 stays 0.5. */
    private function plain(float $cedis): string
    {
        return rtrim(rtrim(number_format($cedis, 2), '0'), '.');
    }

    /**
     * One field of a post, as trimmed text no longer than we can store.
     *
     * @param  array<string, mixed>  $post
     */
    private function text(array $post, string $key, int $limit): string
    {
        $value = $post[$key] ?? null;

        return is_scalar($value) ? Str::limit(trim((string) $value), $limit, '') : '';
    }

    /**
     * A post cut down to what the trail keeps: plain values, none of them long.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function kept(array $post): array
    {
        return collect($post)
            ->filter(fn (mixed $value) => $value === null || is_scalar($value))
            ->take(20)
            ->map(fn (mixed $value) => is_string($value) ? Str::limit($value, 300, '') : $value)
            ->all();
    }
}
