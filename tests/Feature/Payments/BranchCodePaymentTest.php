<?php

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\CheckoutSession;
use App\Models\Employee;
use App\Models\HubtelIncomingPayment;
use App\Models\HubtelPaymentNotification;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\BranchCodePayments;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| A customer pays by the branch code, and the till settles the sale with it
|--------------------------------------------------------------------------
|
| Hubtel posts every payment into a branch's account to us. The post has no
| signature, so each one is asked about with Hubtel's status check before the
| till sees it. The cashier picks the payment to settle a sale, and from then
| on it belongs to that order and cannot settle another.
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
    $branch = Branch::factory()->create([
        'name' => $name,
        'is_active' => true,
        'extended_staff_access' => true,
        'extended_order_access' => true,
    ]);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $branch->operatingHours()->updateOrCreate(
            ['day_of_week' => $day],
            ['is_open' => true, 'open_time' => '00:00', 'close_time' => '23:59'],
        );
    }

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
function bcHubtelSaysPaid(): void
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
            'clientReference' => BC_REFERENCE,
            'amount' => 25.5,
            'charges' => 0.5,
            'amountAfterCharges' => 25,
        ],
    ])]);
}

/** A till sale of the dish, settled with a branch code payment. */
function bcSale(int $paymentId, int $quantity = 1)
{
    return test()->actingAs(test()->cashier)->postJson('/v1/pos/checkout-sessions', [
        'branch_id' => test()->branch->id,
        'items' => [[
            'menu_item_id' => test()->dish->id,
            'menu_item_option_id' => test()->option->id,
            'quantity' => $quantity,
            'unit_price' => 25,
        ]],
        'payment_method' => 'branch_code',
        'hubtel_incoming_payment_id' => $paymentId,
        'fulfillment_type' => 'takeaway',
        'contact_name' => 'Walk-in',
        'contact_phone' => '0000000000',
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
    it('keeps a payment Hubtel calls Paid, against the branch that owns the account', function () {
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

        // Asked of Lakeside's own account, with Lakeside's own key.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions/'.BC_LAKESIDE.'/status')
            && str_contains($r->url(), 'clientReference=')
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('lakeside-id:lakeside-secret'));
    });

    it('leaves a payment the till started alone', function () {
        $lakeside = bcLakeside();
        Http::fake();
        CheckoutSession::create([
            'session_token' => '2670393e-3eea-4a5f-a357-e75ac8f9b8ad',
            'branch_id' => $lakeside->id,
            'session_type' => 'pos',
            'status' => 'confirmed',
            'customer_name' => 'Walk-in',
            'customer_phone' => '0000000000',
            'fulfillment_type' => 'takeaway',
            'payment_method' => 'mobile_money',
            'items' => [],
            'subtotal' => 0.01,
            'total_amount' => 0.01,
            'expires_at' => now()->addMinutes(5),
        ]);

        bcPost(data: ['ClientReference' => '2670393e-3eea-4a5f-a357-e75ac8f9b8ad']);

        expect(HubtelIncomingPayment::count())->toBe(0)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('ours');
        Http::assertNothingSent();
    });

    it('knows our references from Hubtel\'s, including the cut-short ones early sessions sent', function () {
        $lakeside = bcLakeside();
        CheckoutSession::create([
            'session_token' => '2670393e-3eea-4a5f-a357-e75ac8f9b8ad',
            'branch_id' => $lakeside->id,
            'session_type' => 'pos',
            'status' => 'confirmed',
            'customer_name' => 'Walk-in',
            'customer_phone' => '0000000000',
            'fulfillment_type' => 'takeaway',
            'payment_method' => 'mobile_money',
            'items' => [],
            'subtotal' => 1,
            'total_amount' => 1,
            'expires_at' => now()->addMinutes(5),
        ]);
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

    it('does not guess when it holds no key for the account', function () {
        Http::fake();

        bcPost(account: '2040749');

        expect(HubtelIncomingPayment::count())->toBe(0)
            ->and(HubtelPaymentNotification::sole()->outcome)->toBe('no_key');
        Http::assertNothingSent();
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

    it('checks the company account with the company key, for the branch that owns it', function () {
        $ashaiman = bcBranch('Ashaiman', ['hubtel_account_number' => BC_COMPANY]);
        bcHubtelSaysPaid();

        bcPost(account: BC_COMPANY);

        expect(HubtelIncomingPayment::sole()->branch_id)->toBe($ashaiman->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions/'.BC_COMPANY.'/status')
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('company-id:company-secret'));
    });
});

describe('the till', function () {
    beforeEach(function () {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branch = bcLakeside();
        $this->dish = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Jollof', 'is_available' => true]);
        $this->dish->branches()->syncWithoutDetaching([$this->branch->id => ['is_available' => true]]);
        $this->dish->options()->delete();
        $this->option = MenuItemOption::factory()->create(['menu_item_id' => $this->dish->id, 'price' => 25, 'is_available' => true]);

        $user = User::factory()->create();
        $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => EmployeeStatus::Active]);
        $this->employee->branches()->attach($this->branch);
        $user->syncRoles([RoleEnum::SalesStaff->value]);
        $this->cashier = $user->fresh();
    });

    it('lists today\'s unused payments at the branch, with the last four digits only', function () {
        $today = bcIncoming($this->branch);
        bcIncoming($this->branch, ['paid_at' => now()->subDay()]);
        // order_id is not mass assignable; only a claim sets it.
        bcIncoming($this->branch)->forceFill(['order_id' => Order::factory()->create()->id])->save();
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

    it('settles a sale with the payment, and only once', function () {
        $payment = bcIncoming($this->branch);

        bcSale($payment->id)->assertCreated()->assertJsonPath('status', 'confirmed');

        $order = Order::sole();
        $recorded = Payment::where('order_id', $order->id)->sole();
        expect($recorded->payment_method)->toBe('mobile_money')
            ->and($recorded->payment_status)->toBe('completed')
            ->and($recorded->transaction_id)->toBe($payment->network_transaction_id)
            ->and($payment->fresh()->order_id)->toBe($order->id)
            ->and($payment->fresh()->claimed_by)->toBe($this->employee->id);

        bcSale($payment->id)
            ->assertStatus(422)
            ->assertJsonPath('message', "That payment is already on order {$order->order_number}.");
        expect(Order::count())->toBe(1);
    });

    it('refuses a payment that is not the sale\'s amount, before writing anything', function () {
        $payment = bcIncoming($this->branch);

        bcSale($payment->id, quantity: 2)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That payment is GHS 25.00. This sale is GHS 50.00.');

        expect(CheckoutSession::count())->toBe(0)
            ->and($payment->fresh()->order_id)->toBeNull();
    });

    it('refuses another branch\'s payment', function () {
        $payment = bcIncoming(bcBranch('East Legon'));

        bcSale($payment->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'That payment was made to East Legon, not this branch.');
    });
});
