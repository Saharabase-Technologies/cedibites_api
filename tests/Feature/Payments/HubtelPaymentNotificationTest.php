<?php

use App\Models\Branch;
use App\Models\HubtelPaymentNotification;

/*
|--------------------------------------------------------------------------
| Hubtel payment notifications are kept as they arrive
|--------------------------------------------------------------------------
|
| Hubtel's dashboard posts to us whenever a payment into a branch's account
| succeeds or fails, branch code payments included. Its shape is not
| documented, so every post is kept whole until real ones have been read.
|
*/

it('keeps a JSON post against the branch that holds the account', function () {
    $branch = Branch::factory()->create(['name' => 'Lakeside']);
    $branch->forceFill(['hubtel_account_number' => '2040195'])->save();

    $this->postJson('/v1/payments/hubtel/notifications/2040195', [
        'ResponseCode' => '0000',
        'Data' => ['Amount' => 1.0, 'CustomerPhoneNumber' => '233244000000'],
    ])->assertOk();

    $row = HubtelPaymentNotification::sole();
    expect($row->account_number)->toBe('2040195')
        ->and($row->branch_id)->toBe($branch->id)
        ->and($row->method)->toBe('POST')
        ->and($row->payload['Data']['Amount'])->toEqual(1.0)
        ->and($row->raw_body)->toContain('233244000000');
});

it('keeps a form post and its raw body', function () {
    // post() sends no raw body, so the request is built the way a real form post arrives.
    $this->call(
        'POST',
        '/v1/payments/hubtel/notifications/2040749',
        ['status' => 'Success', 'amount' => '12.50'],
        [],
        [],
        ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'],
        'status=Success&amount=12.50',
    )->assertOk();

    $row = HubtelPaymentNotification::sole();
    expect($row->payload)->toBe(['status' => 'Success', 'amount' => '12.50'])
        ->and($row->raw_body)->toBe('status=Success&amount=12.50');
});

it('keeps a post for an account no branch holds yet, with no branch', function () {
    $this->postJson('/v1/payments/hubtel/notifications/2038092', ['a' => 1])->assertOk();

    expect(HubtelPaymentNotification::sole()->branch_id)->toBeNull();
});

it('answers a bare GET, in case the dashboard tests the URL that way', function () {
    $this->get('/v1/payments/hubtel/notifications/2038092')->assertOk();

    $row = HubtelPaymentNotification::sole();
    expect($row->method)->toBe('GET')
        ->and($row->payload)->toBeNull();
});

it('never keeps credentials from the headers', function () {
    $this->withHeaders([
        'Authorization' => 'Basic c2VjcmV0',
        'Cookie' => 'session=abc',
        'X-Hubtel-Signature' => 'sig',
    ])->postJson('/v1/payments/hubtel/notifications/2038092', ['a' => 1])->assertOk();

    $headers = HubtelPaymentNotification::sole()->headers;
    expect($headers)->not->toHaveKey('authorization')
        ->and($headers)->not->toHaveKey('cookie')
        ->and($headers['x-hubtel-signature'])->toBe('sig');
});

it('refuses a path that is not an account number', function () {
    $this->postJson('/v1/payments/hubtel/notifications/lakeside', ['a' => 1])->assertNotFound();

    expect(HubtelPaymentNotification::count())->toBe(0);
});
