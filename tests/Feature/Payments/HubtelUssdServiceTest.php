<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelPaymentNotification;
use App\Models\HubtelUssdFulfilment;
use App\Models\HubtelUssdSession;
use App\Models\User;
use App\Services\Payments\BranchCodePayments;
use App\Services\Payments\UssdPayments;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The branch code is our own USSD service
|--------------------------------------------------------------------------
|
| A customer at Lakeside dials *713*1552*50#. Hubtel posts the dial to us and
| we answer with one screen: "Pay GHS 50.00 to CediBites Lakeside", 1 to pay.
| On 1 we hand Hubtel the amount and the phone goes to the PIN. Because we
| answer the dial we know the branch, so the payment shows on Lakeside's till
| and nobody else's. Hubtel's word that a dial was paid is checked with its
| status check before the till lists it, and we then tell Hubtel we have it.
|
| The posts below are the ones in Hubtel's Programmable Services docs.
|
*/

const USSD_SESSION = '3c796dac28174f739de4262d08409c51';
const USSD_ORDER = 'ac3307bcca7445618071e6b0e41b50b5';
const USSD_HUBTEL_REFERENCE = 'NHbbe724b267c14ff2a20bcc76775e6b6b_233247879103_15642';
const USSD_CONFIRM_50 = "Pay GHS 50.00 to CediBites Lakeside\n1. Pay\n2. Cancel";

beforeEach(function () {
    config()->set('services.hubtel.payment_client_id', 'company-id');
    config()->set('services.hubtel.payment_client_secret', 'company-secret');
    config()->set('services.hubtel.merchant_account_number', '2038092');

    $this->ashaiman = ussdBranch('Ashaiman', '8501');
    $this->lakeside = ussdBranch('Lakeside', '1552');
});

function ussdBranch(string $name, ?string $code): Branch
{
    $branch = Branch::factory()->create(['name' => $name, 'is_active' => true]);
    $branch->forceFill(['hubtel_ussd_code' => $code])->save();

    return $branch->fresh();
}

/**
 * One post from Hubtel to our interaction URL. Every answer must come back
 * 200 and in letters a USSD screen can show, whatever the test is about.
 */
function ussdPost(array $post = [], string $address = '/v1/payments/hubtel/ussd')
{
    $response = test()->postJson($address, array_merge([
        'Type' => 'Initiation',
        'Mobile' => '233247879103',
        'SessionId' => USSD_SESSION,
        'ServiceCode' => '713*1552',
        'Message' => '*713*1552*50#',
        'Operator' => 'mtn',
        'Sequence' => 1,
        'ClientState' => '',
        'Platform' => 'USSD',
    ], $post))->assertOk();

    expect($response->json('Message'))->toMatch('/^[\x20-\x7E\n]+$/');

    return $response;
}

/** The customer typed something on a screen of ours. */
function ussdTyped(string $typed)
{
    return ussdPost(['Type' => 'Response', 'Message' => $typed, 'Sequence' => 2]);
}

/** The whole of a GHS 50 dial at Lakeside, up to the PIN. */
function ussdDialledAndConfirmed(): void
{
    ussdPost();
    ussdTyped('1')->assertJsonPath('Type', 'AddToCart');
}

/** Hubtel's fulfilment for the GHS 50 dial, with its GHS 0.50 fee on top. */
function ussdFulfilment(array $payload = [], string $address = '/v1/payments/hubtel/ussd/fulfilment')
{
    return test()->postJson($address, array_merge([
        'SessionId' => USSD_SESSION,
        'OrderId' => USSD_ORDER,
        'ExtraData' => [],
        'OrderInfo' => [
            'CustomerMobileNumber' => '233247879103',
            'CustomerEmail' => null,
            'CustomerName' => 'Ama Mensah',
            'Status' => 'Paid',
            'OrderDate' => now()->toIso8601String(),
            'Currency' => 'GHS',
            // Hubtel's own name for the branch. Ashaiman and East Legon both read "Ho Central" there.
            'BranchName' => 'Ho Central',
            'IsRecurring' => false,
            'RecurringInvoiceId' => null,
            'Subtotal' => 50.5,
            'Items' => [['ItemId' => '5b8945940e1247489e34e756d8fc2dbb', 'Name' => 'CediBites Lakeside', 'Quantity' => 1, 'UnitPrice' => 50]],
            'Payment' => [
                'PaymentType' => 'mobilemoney',
                'AmountPaid' => 50.5,
                'AmountAfterCharges' => 50,
                'PaymentDate' => now()->toIso8601String(),
                'PaymentDescription' => 'The MTN Mobile Money payment has been approved and processed successfully.',
                'IsSuccessful' => true,
            ],
        ],
    ], $payload))->assertOk();
}

/** What Hubtel's status check says about the dial, and how it takes our callback. */
function ussdHubtelSays(string $status = 'Paid', array $data = [], int $callback = 200): void
{
    Http::fake([
        'api-txnstatus.hubtel.com/*' => Http::response([
            'message' => 'Successful',
            'responseCode' => '0000',
            'data' => array_merge([
                'date' => now()->toIso8601String(),
                'status' => $status,
                'transactionId' => USSD_ORDER,
                'externalTransactionId' => '90967196991',
                'paymentMethod' => 'mobilemoney',
                'clientReference' => USSD_SESSION,
                'amount' => 50.5,
                'charges' => 0.5,
                'amountAfterCharges' => 50,
            ], $data),
        ]),
        'gs-callback.hubtel.com*' => Http::response('', $callback),
    ]);
}

/** Hubtel's business-wide payment notification for the same payment. */
function ussdNotification(string $reference)
{
    return test()->postJson('/v1/payments/hubtel/notifications/2038092', [
        'ResponseCode' => '0000',
        'Message' => 'success',
        'Data' => [
            'Amount' => 50,
            'Charges' => 0.5,
            'AmountAfterCharges' => 50,
            'Description' => null,
            'ClientReference' => $reference,
            'TransactionId' => '1A111C2FC6C103939',
            'ExternalTransactionId' => '90967196991',
            'AmountCharged' => 50.5,
            'OrderId' => USSD_ORDER,
            'PaymentDate' => now()->toIso8601String(),
        ],
    ])->assertOk();
}

function ussdCallbacksSent(): int
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), 'gs-callback.hubtel.com'))->count();
}

describe('the dial', function () {
    it('reads a dial that carries the amount back once, then goes to the PIN', function () {
        ussdPost()
            ->assertJsonPath('SessionId', USSD_SESSION)
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', USSD_CONFIRM_50)
            ->assertJsonPath('DataType', 'input')
            ->assertJsonPath('FieldType', 'number')
            ->assertJsonMissingPath('Item');

        $session = HubtelUssdSession::sole();
        expect($session->branch_id)->toBe($this->lakeside->id)
            ->and($session->mobile)->toBe('233247879103')
            ->and($session->operator)->toBe('mtn')
            ->and((float) $session->amount)->toBe(50.0)
            ->and($session->amount_from)->toBe('dial')
            ->and($session->state)->toBe(HubtelUssdSession::ASKED_CONFIRM)
            ->and($session->dialled)->toBe('*713*1552*50#');

        $response = ussdTyped('1');

        $response->assertJsonPath('SessionId', USSD_SESSION)
            ->assertJsonPath('Type', 'AddToCart')
            ->assertJsonPath('Item.ItemName', 'CediBites Lakeside')
            ->assertJsonPath('Item.Qty', 1)
            ->assertJsonPath('Item.Price', 50)
            ->assertJsonPath('Message', "Pay GHS 50.00 to CediBites Lakeside.\nApprove the MoMo prompt with your PIN.")
            ->assertJsonPath('DataType', 'display');

        // The bytes Hubtel reads: the item as its docs show it, the price a number.
        expect($response->getContent())->toContain('"Item":{"ItemName":"CediBites Lakeside","Qty":1,"Price":50}');

        $session->refresh();
        expect($session->state)->toBe(HubtelUssdSession::SENT_TO_PAY)
            ->and($session->trail)->toHaveCount(2)
            ->and($session->trail[0]['in']['Message'])->toBe('*713*1552*50#')
            ->and($session->trail[0]['out']['Type'])->toBe('response')
            ->and($session->trail[1]['in']['Message'])->toBe('1')
            ->and($session->trail[1]['out']['Type'])->toBe('AddToCart');
    });

    it('reads the dial wherever Hubtel puts it', function (string $serviceCode, string $message) {
        ussdPost(['ServiceCode' => $serviceCode, 'Message' => $message])
            ->assertJsonPath('Message', USSD_CONFIRM_50);

        expect(HubtelUssdSession::sole()->branch_id)->toBe($this->lakeside->id);
    })->with([
        'the whole string in Message, the code in ServiceCode too' => ['713*1552', '*713*1552*50#'],
        'the whole string in Message, a short ServiceCode' => ['713', '*713*1552*50#'],
        'only what followed the code in Message' => ['713*1552', '50'],
        'no star or hash at the ends' => ['*713*1552#', '713*1552*50'],
    ]);

    it('pays the branch whose code was dialled', function () {
        ussdPost(['ServiceCode' => '713*8501', 'Message' => '*713*8501*20#'])
            ->assertJsonPath('Message', "Pay GHS 20.00 to CediBites Ashaiman\n1. Pay\n2. Cancel");

        ussdTyped('1')
            ->assertJsonPath('Item.ItemName', 'CediBites Ashaiman')
            ->assertJsonPath('Item.Price', 20);

        expect(HubtelUssdSession::sole()->branch_id)->toBe($this->ashaiman->id);
    });

    it('sends nothing to pay when the customer presses 2', function () {
        ussdPost();

        ussdTyped('2')
            ->assertJsonPath('Type', 'release')
            ->assertJsonPath('Message', 'Payment cancelled.')
            ->assertJsonMissingPath('Item');

        expect(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::CANCELLED);
    });

    it('takes only a 1 as yes, and asks three times at most', function () {
        ussdPost();

        ussdTyped('yes')
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "Press 1 or 2.\n".USSD_CONFIRM_50);
        ussdTyped('11')->assertJsonPath('Type', 'response');
        ussdTyped('')
            ->assertJsonPath('Type', 'release')
            ->assertJsonPath('Message', 'Nothing was paid. Please dial again.');

        expect(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::REFUSED);
    });

    it('asks for the amount when the dial has none, and takes pesewas', function () {
        ussdPost(['Message' => '*713*1552#'])
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "CediBites Lakeside\nEnter amount to pay (GHS)")
            ->assertJsonPath('DataType', 'input')
            ->assertJsonPath('FieldType', 'decimal');

        ussdTyped('50.5')
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "Pay GHS 50.50 to CediBites Lakeside\n1. Pay\n2. Cancel");

        ussdTyped('1')
            ->assertJsonPath('Type', 'AddToCart')
            ->assertJsonPath('Item.Price', 50.5)
            ->assertJsonPath('Message', "Pay GHS 50.50 to CediBites Lakeside.\nApprove the MoMo prompt with your PIN.");

        $session = HubtelUssdSession::sole();
        expect($session->amount_from)->toBe('typed')
            ->and($session->state)->toBe(HubtelUssdSession::SENT_TO_PAY)
            ->and($session->trail)->toHaveCount(3);
    });

    it('asks again for an amount that is not one, three times at most', function () {
        ussdPost(['Message' => '*713*1552#']);

        ussdTyped('fifty')
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "That is not an amount.\nEnter amount to pay (GHS)");
        ussdTyped('50.555')->assertJsonPath('Type', 'response');
        ussdTyped('.')
            ->assertJsonPath('Type', 'release')
            ->assertJsonPath('Message', 'No amount received. Please dial again.');

        $session = HubtelUssdSession::sole();
        expect($session->state)->toBe(HubtelUssdSession::REFUSED)
            ->and($session->amount)->toBeNull();
    });

    it('does not read back an amount outside the limits', function () {
        ussdPost(['Message' => '*713*1552*6000#'])
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "One payment is GHS 1 to GHS 5,000.\nEnter amount to pay (GHS)");

        // The dial asked nothing, so it has used none of the three tries.
        expect(HubtelUssdSession::sole()->bad_tries)->toBe(0);

        ussdTyped('0')->assertJsonPath('Type', 'response');
        ussdTyped('5000')->assertJsonPath('Message', "Pay GHS 5,000.00 to CediBites Lakeside\n1. Pay\n2. Cancel");
        ussdTyped('1')->assertJsonPath('Type', 'AddToCart')->assertJsonPath('Item.Price', 5000);
    });

    it('asks which branch when the dial names none', function () {
        ussdPost(['ServiceCode' => '713*9999', 'Message' => '*713*9999#'])
            ->assertJsonPath('Type', 'response')
            ->assertJsonPath('Message', "CediBites\nChoose your branch\n1. Ashaiman\n2. Lakeside")
            ->assertJsonPath('FieldType', 'number');

        ussdTyped('7')->assertJsonPath('Message', "That is not on the list.\nChoose your branch\n1. Ashaiman\n2. Lakeside");

        ussdTyped('2')->assertJsonPath('Message', "CediBites Lakeside\nEnter amount to pay (GHS)");

        ussdTyped('30')->assertJsonPath('Message', "Pay GHS 30.00 to CediBites Lakeside\n1. Pay\n2. Cancel");

        ussdTyped('1')
            ->assertJsonPath('Type', 'AddToCart')
            ->assertJsonPath('Item.ItemName', 'CediBites Lakeside')
            ->assertJsonPath('Item.Price', 30);

        expect(HubtelUssdSession::sole()->branch_id)->toBe($this->lakeside->id);
    });

    it('lets a dial the customer abandoned go', function (string $message) {
        ussdPost(['Message' => $message]);

        ussdPost(['Type' => 'Timeout', 'Message' => ''])->assertJsonPath('Type', 'release');

        expect(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::CANCELLED);
    })->with([
        'at the amount' => ['*713*1552#'],
        'at the confirm' => ['*713*1552*50#'],
    ]);

    it('does not undo a hand-over when Hubtel posts the same dial again', function () {
        ussdDialledAndConfirmed();

        ussdPost(['Message' => '*713*1552#'])
            ->assertJsonPath('Type', 'AddToCart')
            ->assertJsonPath('Item.Price', 50);

        expect(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::SENT_TO_PAY);
    });

    it('ends a key press for a dial it never saw', function () {
        ussdTyped('1')->assertJsonPath('Type', 'release');

        expect(HubtelUssdSession::count())->toBe(0);
    });

    it('answers in Hubtel\'s shape even for a post with nothing in it', function () {
        $this->postJson('/v1/payments/hubtel/ussd', [])
            ->assertOk()
            ->assertJsonPath('Type', 'release')
            ->assertJsonStructure(['SessionId', 'Type', 'Message', 'Label', 'DataType', 'FieldType']);

        expect(HubtelUssdSession::count())->toBe(0);
    });

    it('says so when no branch has a code yet', function () {
        Branch::query()->update(['hubtel_ussd_code' => null]);

        ussdPost()
            ->assertJsonPath('Type', 'release')
            ->assertJsonPath('Message', 'This code is not ready yet. Please pay at the counter.');

        expect(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::REFUSED);
    });
});

describe('a branch\'s own address', function () {
    it('knows the branch from the address when the dial does not say', function () {
        // Opened from the Hubtel app: no dial string at all.
        ussdPost(['ServiceCode' => 'e3f1c0', 'Message' => '', 'Platform' => 'Hubtel-App'], '/v1/payments/hubtel/ussd/1552')
            ->assertJsonPath('Message', "CediBites Lakeside\nEnter amount to pay (GHS)");

        expect(HubtelUssdSession::sole()->branch_id)->toBe($this->lakeside->id);
    });

    it('reads the amount after a code that is not the branch\'s own', function () {
        // Hubtel attached Lakeside's service to a fresh code, *713*4040#.
        ussdPost(['ServiceCode' => '713*4040', 'Message' => '*713*4040*50#'], '/v1/payments/hubtel/ussd/1552')
            ->assertJsonPath('Message', USSD_CONFIRM_50);
    });

    it('goes by the address when the address and the dial disagree', function () {
        ussdPost(['ServiceCode' => '713*8501', 'Message' => '*713*8501*50#'], '/v1/payments/hubtel/ussd/1552')
            ->assertJsonPath('Message', USSD_CONFIRM_50);

        expect(HubtelUssdSession::sole()->branch_id)->toBe($this->lakeside->id);
    });

    it('falls back to the dial when the address carries a code no branch has', function () {
        ussdPost([], '/v1/payments/hubtel/ussd/7777')
            ->assertJsonPath('Message', USSD_CONFIRM_50);
    });

    it('takes the fulfilment at the branch\'s address too', function () {
        ussdDialledAndConfirmed();
        ussdHubtelSays();

        ussdFulfilment(address: '/v1/payments/hubtel/ussd/1552/fulfilment');

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($this->lakeside->id)
            ->and(HubtelUssdFulfilment::sole()->outcome)->toBe('paid');
    });
});

describe('a paid dial', function () {
    beforeEach(function () {
        ussdDialledAndConfirmed();
    });

    it('lists it on the branch that was dialled, with the MoMo name, and tells Hubtel', function () {
        ussdHubtelSays();

        ussdFulfilment();

        $payment = HubtelIncomingPayment::sole();
        $session = HubtelUssdSession::sole();

        // Our session's branch, not the "Ho Central" in Hubtel's post.
        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->ussd_session_id)->toBe($session->id)
            ->and($payment->client_reference)->toBe(USSD_SESSION)
            ->and((float) $payment->amount)->toBe(50.0)
            ->and((float) $payment->amount_charged)->toBe(50.5)
            ->and($payment->payer_number)->toBe('233247879103')
            ->and($payment->payer_name)->toBe('Ama Mensah')
            ->and($payment->network_transaction_id)->toBe('90967196991')
            ->and($payment->account_number)->toBeNull()
            ->and(HubtelUssdFulfilment::sole()->outcome)->toBe('paid')
            ->and($session->state)->toBe(HubtelUssdSession::PAID)
            ->and($session->hubtel_order_id)->toBe(USSD_ORDER)
            ->and($session->acked_at)->not->toBeNull();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api-txnstatus.hubtel.com')
            && str_contains($r->url(), 'clientReference='.USSD_SESSION));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://gs-callback.hubtel.com:9055/callback'
            && $r['SessionId'] === USSD_SESSION
            && $r['OrderId'] === USSD_ORDER
            && $r['ServiceStatus'] === 'success');
    });

    it('keeps one payment and sends one callback however many times Hubtel posts', function () {
        ussdHubtelSays();

        ussdFulfilment();
        ussdFulfilment();

        expect(HubtelIncomingPayment::count())->toBe(1)
            ->and(HubtelUssdFulfilment::orderBy('id')->pluck('outcome')->all())->toBe(['paid', 'duplicate'])
            ->and(ussdCallbacksSent())->toBe(1);
    });

    it('does not trust a fulfilment Hubtel cannot find', function () {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '404', 'message' => 'payment record not found'], 404)]);
        $fulfilment = HubtelUssdFulfilment::create([
            'session_id' => USSD_SESSION,
            'payload' => ['SessionId' => USSD_SESSION, 'OrderId' => USSD_ORDER, 'OrderInfo' => ['Status' => 'Paid']],
        ]);
        $payments = app(UssdPayments::class);

        expect($payments->verify($fulfilment))->toBe(UssdPayments::RETRY)
            ->and($payments->verify($fulfilment, lastAttempt: true))->toBe('not_found')
            ->and(HubtelIncomingPayment::count())->toBe(0)
            ->and(ussdCallbacksSent())->toBe(0);
    });

    it('finds the payment by Hubtel\'s order ID when the session ID is not its reference', function () {
        Http::fake([
            'api-txnstatus.hubtel.com/*' => fn (Request $r) => str_contains($r->url(), 'hubtelTransactionId='.USSD_ORDER)
                ? Http::response(['responseCode' => '0000', 'data' => [
                    'date' => now()->toIso8601String(),
                    'status' => 'Paid',
                    'transactionId' => USSD_ORDER,
                    'externalTransactionId' => '90967196991',
                    'amount' => 50.5,
                    'amountAfterCharges' => 50,
                ]])
                : Http::response(['responseCode' => '404', 'message' => 'payment record not found'], 404),
            'gs-callback.hubtel.com*' => Http::response(''),
        ]);

        ussdFulfilment();

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($this->lakeside->id)
            ->and(HubtelUssdFulfilment::sole()->outcome)->toBe('paid');
    });

    it('lists nothing when Hubtel says the dial is not paid', function () {
        ussdHubtelSays('Unpaid');
        $fulfilment = HubtelUssdFulfilment::create([
            'session_id' => USSD_SESSION,
            'payload' => ['SessionId' => USSD_SESSION, 'OrderId' => USSD_ORDER, 'OrderInfo' => ['Status' => 'Paid']],
        ]);

        expect(app(UssdPayments::class)->verify($fulfilment, lastAttempt: true))->toBe('not_paid')
            ->and(HubtelIncomingPayment::count())->toBe(0)
            ->and(HubtelUssdSession::sole()->state)->toBe(HubtelUssdSession::SENT_TO_PAY)
            ->and(ussdCallbacksSent())->toBe(0);
    });

    it('lets a fulfilment that says it failed go without asking', function () {
        Http::fake();

        ussdFulfilment(['OrderInfo' => ['Status' => 'Unpaid', 'Payment' => ['IsSuccessful' => false]]]);

        expect(HubtelUssdFulfilment::sole()->outcome)->toBe('failed')
            ->and(HubtelIncomingPayment::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('refuses a payment for a different amount than the dial asked for', function () {
        ussdHubtelSays(data: ['amount' => 500.5, 'amountAfterCharges' => 500]);

        ussdFulfilment();

        expect(HubtelUssdFulfilment::sole()->outcome)->toBe('mismatch')
            ->and(HubtelIncomingPayment::count())->toBe(0)
            ->and(ussdCallbacksSent())->toBe(0);
    });

    it('keeps a fulfilment for a dial we never answered, and lists nothing', function () {
        Http::fake();

        ussdFulfilment(['SessionId' => 'ffffffffffffffffffffffffffffffff']);

        $fulfilment = HubtelUssdFulfilment::sole();
        expect($fulfilment->outcome)->toBe('unknown_session')
            ->and($fulfilment->payload['OrderInfo']['CustomerName'])->toBe('Ama Mensah')
            ->and($fulfilment->raw_body)->toContain('ffffffffffffffffffffffffffffffff')
            ->and(HubtelIncomingPayment::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('writes down what Hubtel said when it refuses our callback', function () {
        ussdHubtelSays(callback: 403);

        ussdFulfilment();

        $session = HubtelUssdSession::sole();
        expect(HubtelIncomingPayment::count())->toBe(1)
            ->and($session->acked_at)->toBeNull()
            ->and($session->ack_note)->toStartWith('HTTP 403');
    });
});

describe('the same payment told twice', function () {
    beforeEach(function () {
        ussdDialledAndConfirmed();
        ussdHubtelSays();
    });

    it('lists it once, on the right branch, when the notification names the session and comes first', function () {
        ussdNotification(USSD_SESSION);

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($this->lakeside->id)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('paid')
            ->and(ussdCallbacksSent())->toBe(0);

        ussdFulfilment();

        $payment = HubtelIncomingPayment::sole();
        expect($payment->payer_name)->toBe('Ama Mensah')
            ->and(HubtelUssdFulfilment::sole()->outcome)->toBe('duplicate')
            ->and(ussdCallbacksSent())->toBe(1);
    });

    it('gives it its branch when the notification used Hubtel\'s own reference and came first', function () {
        ussdNotification(USSD_HUBTEL_REFERENCE);

        expect(HubtelIncomingPayment::sole()->branch_id)->toBeNull();

        ussdFulfilment();

        $payment = HubtelIncomingPayment::sole();
        expect($payment->branch_id)->toBe($this->lakeside->id)
            ->and($payment->ussd_session_id)->toBe(HubtelUssdSession::sole()->id)
            ->and($payment->payer_name)->toBe('Ama Mensah');
    });

    it('lists it once when the notification comes second', function (string $reference) {
        ussdFulfilment();
        ussdNotification($reference);

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($this->lakeside->id)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('duplicate');
    })->with([
        'under the session ID' => [USSD_SESSION],
        'under Hubtel\'s own reference' => [USSD_HUBTEL_REFERENCE],
    ]);
});

describe('the till', function () {
    it('shows the payment at its own branch with the MoMo name, and at no other', function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['name' => 'Rosina']);
        $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $employee->branches()->attach($this->lakeside);
        $user->syncRoles([RoleEnum::SalesStaff->value]);

        ussdDialledAndConfirmed();
        ussdHubtelSays();
        ussdFulfilment();

        $this->actingAs($user->fresh())
            ->getJson('/v1/pos/branch-code-payments?branch_id='.$this->lakeside->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 50)
            ->assertJsonPath('data.0.payer_name', 'Ama Mensah')
            ->assertJsonPath('data.0.payer_last_four', '9103');

        expect(app(BranchCodePayments::class)->today($this->ashaiman->id))->toHaveCount(0);
    });
});

describe('the command', function () {
    it('gives a branch its code, and takes it away', function () {
        $eastLegon = ussdBranch('East Legon', null);

        $this->artisan('hubtel:ussd-code', ['branch' => 'East Legon', 'code' => '7856'])->assertSuccessful();
        expect($eastLegon->fresh()->hubtel_ussd_code)->toBe('7856');

        $this->artisan('hubtel:ussd-code', ['branch' => 'East Legon', '--clear' => true])->assertSuccessful();
        expect($eastLegon->fresh()->hubtel_ussd_code)->toBeNull();
    });

    it('will not give two branches one code, or take something that is not a code', function (string $code) {
        $this->artisan('hubtel:ussd-code', ['branch' => 'Ashaiman', 'code' => $code])->assertFailed();

        expect($this->ashaiman->fresh()->hubtel_ussd_code)->toBe('8501');
    })->with([
        'Lakeside\'s code' => ['1552'],
        'a whole dial string' => ['*713*8501#'],
    ]);

    it('lists every branch with its code', function () {
        $this->artisan('hubtel:ussd-code')
            ->expectsTable(['Id', 'Branch', 'Code'], [
                [$this->ashaiman->id, 'Ashaiman', '8501'],
                [$this->lakeside->id, 'Lakeside', '1552'],
            ])
            ->assertSuccessful();
    });
});
