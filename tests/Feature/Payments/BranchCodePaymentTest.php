<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelPaymentCheck;
use App\Models\HubtelPaymentNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\BranchCodePayments;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The till can check that a branch code payment really arrived
|--------------------------------------------------------------------------
|
| The cashier rings the sale, often as cash, and the customer then pays by
| dialling the branch code. Hubtel posts each payment to us; the post has no
| signature, so each one is asked about with Hubtel's status check before the
| till lists it. A cashier can also type the transaction ID from the
| customer's MoMo message, and the same message checked twice is caught.
|
*/

const BC_COMPANY = '2038092';
const BC_LAKESIDE = '2040195';
const BC_REFERENCE = 'NHbbe724b267c14ff2a20bcc76775e6b6b_233247879103_15642';

beforeEach(function () {
    config()->set('services.hubtel.payment_client_id', 'company-id');
    config()->set('services.hubtel.payment_client_secret', 'company-secret');
    config()->set('services.hubtel.merchant_account_number', BC_COMPANY);
});

function bcBranch(string $name, array $hubtel = []): Branch
{
    $branch = Branch::factory()->create(['name' => $name, 'is_active' => true]);

    if ($hubtel) {
        $branch->forceFill($hubtel)->save();
    }

    return $branch->fresh();
}

function bcLakeside(): Branch
{
    return bcBranch('Lakeside', [
        'hubtel_account_number' => BC_LAKESIDE,
        'hubtel_api_id' => 'lakeside-id',
        'hubtel_api_key' => 'lakeside-secret',
    ]);
}

function bcSession(Branch $branch, string $token, ?int $orderId = null): CheckoutSession
{
    return CheckoutSession::create([
        'session_token' => $token,
        'branch_id' => $branch->id,
        'session_type' => 'pos',
        'status' => 'confirmed',
        'customer_name' => 'Walk-in',
        'customer_phone' => '0000000000',
        'fulfillment_type' => 'takeaway',
        'payment_method' => 'mobile_money',
        'items' => [],
        'subtotal' => 90,
        'total_amount' => 90,
        'order_id' => $orderId,
        'expires_at' => now()->addMinutes(5),
    ]);
}

/** Hubtel's post, as it arrived on 2026-10-06 for a GHS 25 branch code payment. */
function bcPost(string $account = BC_LAKESIDE, array $data = [], string $code = '0000')
{
    return test()->postJson("/v1/payments/hubtel/notifications/{$account}", [
        'ResponseCode' => $code,
        'Message' => 'success',
        'Data' => array_merge([
            'Amount' => 25,
            'Charges' => 0.5,
            'AmountAfterCharges' => 25,
            'Description' => null,
            'ClientReference' => BC_REFERENCE,
            'TransactionId' => '1A111C2FC6C103939',
            'ExternalTransactionId' => '90967196991',
            'AmountCharged' => 25.5,
            'OrderId' => 'ca1e739b69d0404d9e5cfc65e0670e89',
            'PaymentDate' => now()->toIso8601String(),
        ], $data),
    ])->assertOk();
}

/** Hubtel's status check answering Paid, as it did for that payment. */
function bcHubtelSaysPaid(string $reference = BC_REFERENCE): void
{
    Http::fake(['api-txnstatus.hubtel.com/*' => Http::response([
        'message' => 'Successful',
        'responseCode' => '0000',
        'data' => [
            'date' => now()->toIso8601String(),
            'status' => 'Paid',
            'transactionId' => 'ca1e739b69d0404d9e5cfc65e0670e89',
            'externalTransactionId' => '90967196991',
            'paymentMethod' => 'mobilemoney',
            'clientReference' => $reference,
            'amount' => 25.5,
            'charges' => 0.5,
            'amountAfterCharges' => 25,
        ],
    ])]);
}

/** The cashier types a transaction ID from the customer's MoMo message. */
function bcCheck(string $transactionId)
{
    return test()->actingAs(test()->cashier)->postJson('/v1/pos/branch-code-payments/check', [
        'branch_id' => test()->branch->id,
        'transaction_id' => $transactionId,
    ]);
}

function bcIncoming(Branch $branch, array $attrs = []): HubtelIncomingPayment
{
    static $n = 0;
    $n++;

    return HubtelIncomingPayment::create(array_merge([
        'branch_id' => $branch->id,
        'account_number' => BC_LAKESIDE,
        'client_reference' => "NHref{$n}_233247879103_1",
        'network_transaction_id' => "9096719699{$n}",
        'payer_number' => '233247879103',
        'amount' => 25,
        'amount_charged' => 25.5,
        'paid_at' => now(),
        'verified_at' => now(),
    ], $attrs));
}

describe('checking what Hubtel posts', function () {
    it('keeps a payment Hubtel calls Paid, against the branch the post was for', function () {
        $lakeside = bcLakeside();
        bcHubtelSaysPaid();

        bcPost();

        $payment = HubtelIncomingPayment::sole();
        expect($payment->branch_id)->toBe($lakeside->id)
            ->and((float) $payment->amount)->toBe(25.0)
            ->and((float) $payment->amount_charged)->toBe(25.5)
            ->and($payment->payer_number)->toBe('233247879103')
            ->and($payment->network_transaction_id)->toBe('90967196991')
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('paid');

        // The company key reaches every branch's payments.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'clientReference=')
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('company-id:company-secret'));
    });

    it('leaves a payment the till started alone', function () {
        bcSession(bcLakeside(), '2670393e-3eea-4a5f-a357-e75ac8f9b8ad');
        Http::fake();

        bcPost(data: ['ClientReference' => '2670393e-3eea-4a5f-a357-e75ac8f9b8ad']);

        expect(HubtelIncomingPayment::count())->toBe(0)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('ours');
        Http::assertNothingSent();
    });

    it('knows our references from Hubtel\'s, including the cut-short ones early sessions sent', function () {
        bcSession(bcLakeside(), '2670393e-3eea-4a5f-a357-e75ac8f9b8ad');
        $payments = app(BranchCodePayments::class);

        expect($payments->isOurs('2670393e-3eea-4a5f-a357-e75ac8f9b8ad'))->toBeTrue()
            ->and($payments->isOurs('2670393e-3eea-4a5f-a357-e75ac8f9'))->toBeTrue()
            ->and($payments->isOurs(BC_REFERENCE))->toBeFalse()
            ->and($payments->isOurs('cb-key-check-a1b2c3'))->toBeTrue();
    });

    it('does not trust a post Hubtel cannot find', function () {
        bcLakeside();
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '404', 'message' => 'payment record not found'], 404)]);
        $notification = HubtelPaymentNotification::create([
            'account_number' => BC_LAKESIDE,
            'method' => 'POST',
            'payload' => ['ResponseCode' => '0000', 'Data' => ['ClientReference' => 'NHforged_233200000000_1', 'Amount' => 500]],
        ]);
        $payments = app(BranchCodePayments::class);

        expect($payments->verify($notification))->toBe(BranchCodePayments::RETRY)
            ->and($payments->verify($notification, lastAttempt: true))->toBe('not_found')
            ->and(HubtelIncomingPayment::count())->toBe(0);
    });

    it('keeps one payment however many times Hubtel posts it', function () {
        bcLakeside();
        bcHubtelSaysPaid();

        bcPost();
        bcPost();

        expect(HubtelIncomingPayment::count())->toBe(1)
            ->and(HubtelPaymentNotification::orderBy('id')->pluck('outcome')->all())->toBe(['paid', 'duplicate']);
    });

    it('lets a failed payment go', function () {
        bcLakeside();
        Http::fake();

        bcPost(code: '2001');

        expect(HubtelIncomingPayment::count())->toBe(0)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('failed');
        Http::assertNothingSent();
    });

    it('puts a payment to the company account on the branch that owns it', function () {
        $ashaiman = bcBranch('Ashaiman', ['hubtel_account_number' => BC_COMPANY]);
        bcHubtelSaysPaid();

        bcPost(account: BC_COMPANY);

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($ashaiman->id);
    });
});

describe('the till', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = bcLakeside();

        $user = User::factory()->create(['name' => 'Rosina']);
        $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $this->employee->branches()->attach($this->branch);
        $user->syncRoles([RoleEnum::SalesStaff->value]);
        $this->cashier = $user->fresh();
    });

    it('lists today\'s payments at the branch, with the last four digits only', function () {
        $today = bcIncoming($this->branch);
        bcIncoming($this->branch, ['paid_at' => now()->subDay()]);
        bcIncoming(bcBranch('East Legon'));

        $response = $this->actingAs($this->cashier)
            ->getJson('/v1/pos/branch-code-payments?branch_id='.$this->branch->id)
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($today->id)
            ->and($response->json('data.0.payer_last_four'))->toBe('9103')
            ->and($response->getContent())->not->toContain('233247879103');
    });

    it('does not show one branch\'s payments to another branch\'s cashier', function () {
        $other = bcBranch('East Legon');

        $this->actingAs($this->cashier)
            ->getJson('/v1/pos/branch-code-payments?branch_id='.$other->id)
            ->assertForbidden();
    });

    it('answers a check from the list without asking Hubtel', function () {
        bcIncoming($this->branch, ['network_transaction_id' => '90967196991']);
        Http::fake();

        bcCheck('9096 7196 991')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'paid')
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.payer_last_four', '9103')
            ->assertJsonPath('data.branch', 'Lakeside')
            ->assertJsonPath('data.first_checked', null);

        Http::assertNothingSent();
        expect(HubtelPaymentCheck::sole()->employee_id)->toBe($this->employee->id);
    });

    it('asks Hubtel about an ID that is not on the list', function () {
        bcHubtelSaysPaid();

        bcCheck('90967196991')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'paid')
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.payer_last_four', '9103');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'networkTransactionId=90967196991'));
    });

    it('says when the payment was a MoMo prompt the till sent, and for which order', function () {
        $order = Order::factory()->create(['order_number' => 'AJ664']);
        bcSession($this->branch, 'c72ed47d-566e-48c3-991a-bd2382a4b627', $order->id);
        bcHubtelSaysPaid('c72ed47d-566e-48c3-991a-bd2382a4b627');

        bcCheck('90969345699')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'ours')
            ->assertJsonPath('data.order_number', 'AJ664');
    });

    it('says plainly when Hubtel has no such payment', function () {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '404', 'message' => 'payment record not found'], 404)]);

        bcCheck('12345678901')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'not_found');
    });

    it('catches the same message checked a second time', function () {
        bcIncoming($this->branch, ['network_transaction_id' => '90967196991']);

        bcCheck('90967196991')->assertJsonPath('data.first_checked', null);

        bcCheck('90967196991')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'paid')
            ->assertJsonPath('data.first_checked.branch', 'Lakeside')
            ->assertJsonPath('data.first_checked.by', 'Rosina');
    });

    it('refuses something too short to be a transaction ID', function () {
        Http::fake();

        bcCheck('123')->assertStatus(422);

        Http::assertNothingSent();
    });

    it('does not let another branch\'s cashier check on its behalf', function () {
        $other = bcBranch('East Legon');

        $this->actingAs($this->cashier)
            ->postJson('/v1/pos/branch-code-payments/check', ['branch_id' => $other->id, 'transaction_id' => '90967196991'])
            ->assertForbidden();
    });
});
