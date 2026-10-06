<?php

use App\Models\Branch;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutSession;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Models\Order;
use App\Models\Payment;
use App\Services\HubtelPaymentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Each branch collects into its own Hubtel account
|--------------------------------------------------------------------------
|
| Hubtel gives every branch a Collection Account, but the app only knew the
| one in the environment (Ashaiman's), so every branch's money landed there.
| A branch given its account and key by `hubtel:branch-account` now charges
| into it. A branch without one carries on exactly as before.
|
*/

const HB_COMPANY = '2038092';
const HB_LAKESIDE = '2040195';

beforeEach(function () {
    config()->set('services.hubtel.payment_client_id', 'company-id');
    config()->set('services.hubtel.payment_client_secret', 'company-secret');
    config()->set('services.hubtel.rmp_client_id', 'company-id');
    config()->set('services.hubtel.rmp_client_secret', 'company-secret');
    config()->set('services.hubtel.merchant_account_number', HB_COMPANY);
});

function hbBasic(string $id, string $secret): string
{
    return 'Basic '.base64_encode("{$id}:{$secret}");
}

function hbBranch(array $hubtel = []): Branch
{
    $branch = Branch::factory()->create(['is_active' => true, 'name' => 'Lakeside']);

    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $branch->operatingHours()->updateOrCreate(
            ['day_of_week' => $day],
            ['is_open' => true, 'open_time' => '00:00', 'close_time' => '23:59'],
        );
    }

    $branch->orderTypes()->updateOrCreate(['order_type' => 'pickup'], ['is_enabled' => true]);
    $branch->paymentMethods()->updateOrCreate(['payment_method' => 'momo'], ['is_enabled' => true]);

    if ($hubtel) {
        $branch->forceFill($hubtel)->save();
    }

    return $branch->fresh();
}

function hbLakesideKeys(): array
{
    return [
        'hubtel_account_number' => HB_LAKESIDE,
        'hubtel_api_id' => 'lakeside-id',
        'hubtel_api_key' => 'lakeside-secret',
    ];
}

/** A guest cart with one ₵50 line at this branch. */
function hbCart(Branch $branch, string $guest): void
{
    $item = MenuItem::factory()->create(['branch_id' => $branch->id, 'is_available' => true]);
    $item->branches()->syncWithoutDetaching([$branch->id => ['is_available' => true]]);

    $option = MenuItemOption::factory()->create([
        'menu_item_id' => $item->id,
        'price' => 50.00,
        'is_available' => true,
    ]);

    $cart = Cart::create([
        'branch_id' => $branch->id,
        'session_id' => $guest,
        'customer_id' => null,
        'status' => 'active',
    ]);

    CartItem::create([
        'cart_id' => $cart->id,
        'menu_item_id' => $item->id,
        'menu_item_option_id' => $option->id,
        'quantity' => 1,
        'unit_price' => 50.00,
        'subtotal' => 50.00,
    ]);
}

function hbMomoCheckout(Branch $branch, string $guest)
{
    hbCart($branch, $guest);

    return test()->withHeaders(['X-Guest-Session' => $guest])
        ->postJson('/v1/checkout-sessions', [
            'branch_id' => $branch->id,
            'customer_name' => 'Akosua Mensah',
            'customer_phone' => '+233241234567',
            'order_type' => 'pickup',
            'payment_method' => 'mobile_money',
            'momo_number' => '+233241234567',
        ]);
}

function hbFakePrompt(): void
{
    Http::fake([
        'rmp.hubtel.com/*' => Http::response([
            'ResponseCode' => '0001',
            'Message' => 'Transaction pending. Expect callback request for final state',
            'Data' => ['TransactionId' => 'hub-tx-1', 'ClientReference' => 'x'],
        ]),
    ]);
}

it('sends the prompt to the branch account with the branch key', function () {
    hbFakePrompt();
    $branch = hbBranch(hbLakesideKeys());

    hbMomoCheckout($branch, 'guest-hubtel-branch-own-account')->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/merchants/'.HB_LAKESIDE.'/receive/mobilemoney')
        && $r->header('Authorization')[0] === hbBasic('lakeside-id', 'lakeside-secret'));

    expect(CheckoutSession::latest('id')->first()->hubtel_account_number)->toBe(HB_LAKESIDE);
});

it('keeps a branch without its own account on the company account', function () {
    hbFakePrompt();
    $branch = hbBranch();

    hbMomoCheckout($branch, 'guest-hubtel-branch-company-account')->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/merchants/'.HB_COMPANY.'/receive/mobilemoney')
        && $r->header('Authorization')[0] === hbBasic('company-id', 'company-secret'));

    expect(CheckoutSession::latest('id')->first()->hubtel_account_number)->toBe(HB_COMPANY);
});

it('treats half a set of keys as none', function () {
    hbFakePrompt();
    $branch = hbBranch(['hubtel_account_number' => HB_LAKESIDE, 'hubtel_api_id' => 'lakeside-id']);

    hbMomoCheckout($branch, 'guest-hubtel-branch-half-a-set')->assertCreated();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/merchants/'.HB_COMPANY.'/'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), HB_LAKESIDE));
});

it('names the branch account on the hosted checkout', function () {
    Http::fake([
        'payproxyapi.hubtel.com/*' => Http::response(['data' => ['checkoutId' => 'c-1', 'checkoutUrl' => 'https://pay.hubtel.com/c-1']]),
    ]);
    $branch = hbBranch(hbLakesideKeys());

    app(HubtelPaymentService::class)->forBranch($branch)->initializeTransaction([
        'order' => (object) ['id' => 1, 'order_number' => 'ref-1', 'total_amount' => 50, 'delivery_fee' => 0, 'contact_name' => 'A', 'contact_phone' => '233241234567'],
        'description' => 'Order at Lakeside',
    ]);

    Http::assertSent(fn (Request $r) => $r['merchantAccountNumber'] === HB_LAKESIDE
        && $r->header('Authorization')[0] === hbBasic('lakeside-id', 'lakeside-secret'));
});

it('carries the account from the session to the payment when the prompt is paid', function () {
    hbFakePrompt();
    $branch = hbBranch(hbLakesideKeys());
    hbMomoCheckout($branch, 'guest-hubtel-branch-prompt-paid')->assertCreated();
    $session = CheckoutSession::latest('id')->first();

    app(HubtelPaymentService::class)->handleRmpCallback([
        'ResponseCode' => '0000',
        'Message' => 'success',
        'Data' => ['ClientReference' => $session->session_token, 'TransactionId' => 'hub-tx-1', 'Amount' => 50.5],
    ]);

    $payment = Payment::where('order_id', $session->fresh()->order_id)->first();

    expect($payment)->not->toBeNull()
        ->and($payment->hubtel_account_number)->toBe(HB_LAKESIDE);
});

it('asks the account a payment went to, not the one the branch uses today', function () {
    Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['status' => 'Paid', 'amount' => 50])]);
    $branch = hbBranch(hbLakesideKeys());

    $onLakeside = Order::factory()->create(['branch_id' => $branch->id]);
    Payment::create(['order_id' => $onLakeside->id, 'payment_method' => 'mobile_money', 'payment_status' => 'pending', 'amount' => 50, 'hubtel_account_number' => HB_LAKESIDE]);

    // Paid the morning Lakeside moved over: no account recorded, so the company one.
    $before = Order::factory()->create(['branch_id' => $branch->id]);
    Payment::create(['order_id' => $before->id, 'payment_method' => 'mobile_money', 'payment_status' => 'pending', 'amount' => 50]);

    $hubtel = app(HubtelPaymentService::class);
    $hubtel->verifyTransaction($onLakeside->order_number);
    $hubtel->verifyTransaction($before->order_number);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions/'.HB_LAKESIDE.'/status')
        && str_contains($r->url(), urlencode($onLakeside->order_number))
        && $r->header('Authorization')[0] === hbBasic('lakeside-id', 'lakeside-secret'));

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions/'.HB_COMPANY.'/status')
        && str_contains($r->url(), urlencode($before->order_number))
        && $r->header('Authorization')[0] === hbBasic('company-id', 'company-secret'));
});

it('never puts the key in a response, and stores it encrypted', function () {
    $branch = hbBranch(hbLakesideKeys());

    expect($branch->toArray())->not->toHaveKey('hubtel_api_key')
        ->and($branch->toArray())->not->toHaveKey('hubtel_api_id')
        ->and(json_encode($branch))->not->toContain('lakeside-secret');

    $stored = DB::table('branches')->where('id', $branch->id)->value('hubtel_api_key');

    expect($stored)->not->toBe('lakeside-secret')
        ->and($branch->hubtelAccount()['api_key'])->toBe('lakeside-secret');
});

it('falls back to the company account when the key cannot be read', function () {
    $branch = hbBranch(hbLakesideKeys());
    DB::table('branches')->where('id', $branch->id)->update(['hubtel_api_key' => 'not-encrypted-with-this-app-key']);

    $hubtel = app(HubtelPaymentService::class)->forBranch($branch->fresh());

    expect($hubtel->accountNumber())->toBe(HB_COMPANY);
});

describe('hubtel:branch-account', function () {
    it('saves a key Hubtel accepts', function () {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['message' => 'Transaction not found'], 200)]);
        $branch = hbBranch();

        $this->artisan('hubtel:branch-account', ['branch' => 'lakeside', '--account' => HB_LAKESIDE, '--api-id' => 'lakeside-id'])
            ->expectsQuestion('API Key (the password). Typing is hidden', 'lakeside-secret')
            ->assertSuccessful();

        expect($branch->fresh()->hubtelAccount())->toBe([
            'account_number' => HB_LAKESIDE,
            'api_id' => 'lakeside-id',
            'api_key' => 'lakeside-secret',
        ]);

        // The probe asked with the new key, not the company one.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions/'.HB_LAKESIDE.'/status')
            && $r->header('Authorization')[0] === hbBasic('lakeside-id', 'lakeside-secret'));
    });

    it('does not save a key Hubtel refuses', function () {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response('', 401)]);
        $branch = hbBranch();

        $this->artisan('hubtel:branch-account', ['branch' => 'Lakeside', '--account' => HB_LAKESIDE, '--api-id' => 'lakeside-id'])
            ->expectsQuestion('API Key (the password). Typing is hidden', 'wrong-secret')
            ->expectsOutputToContain('Hubtel refused the API ID or key')
            ->expectsConfirmation('Save it anyway? Until this is fixed, every MoMo payment at Lakeside would fail.', 'no')
            ->assertFailed();

        expect($branch->fresh()->hubtelAccount())->toBeNull();
    });

    it('says when the server IP is not whitelisted', function () {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response('', 403)]);
        hbBranch();

        $this->artisan('hubtel:branch-account', ['branch' => 'Lakeside', '--account' => HB_LAKESIDE, '--api-id' => 'lakeside-id'])
            ->expectsQuestion('API Key (the password). Typing is hidden', 'lakeside-secret')
            ->expectsOutputToContain("Hubtel refused this server's IP address")
            ->expectsConfirmation('Save it anyway? Until this is fixed, every MoMo payment at Lakeside would fail.', 'no')
            ->assertFailed();
    });

    it('refuses the company account as a branch account', function () {
        hbBranch();

        $this->artisan('hubtel:branch-account', ['branch' => 'Lakeside', '--account' => HB_COMPANY, '--api-id' => 'x'])
            ->expectsOutputToContain('is the company account')
            ->assertFailed();
    });

    it('clears a branch back to the company account', function () {
        $branch = hbBranch(hbLakesideKeys());

        $this->artisan('hubtel:branch-account', ['branch' => 'Lakeside', '--clear' => true])
            ->expectsConfirmation('Lakeside will collect into the company account, '.HB_COMPANY.', from the next payment. Go ahead?', 'yes')
            ->assertSuccessful();

        expect($branch->fresh()->hubtelAccount())->toBeNull()
            ->and($branch->fresh()->hubtel_account_number)->toBeNull();
    });
});
