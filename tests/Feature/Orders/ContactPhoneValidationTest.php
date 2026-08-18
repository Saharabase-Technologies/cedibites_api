<?php

use App\Http\Requests\StoreOrderFromCartRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\StorePosOrderRequest;
use Illuminate\Support\Facades\Validator;

/*
|--------------------------------------------------------------------------
| A customer phone number has to be a phone number
|--------------------------------------------------------------------------
|
| `contact_phone` was validated as `string|max:20` on all three order-creation
| paths, while `momo_number` in the very same POS request already carried a
| Ghana regex. So `0000000000` and `1234567890` saved cleanly, and that is most
| of the reason unreachable numbers are in the data at all.
|
| The rules are asserted directly rather than through the endpoints: the three
| requests have different auth, branch and item requirements, and routing round
| all of that would test the fixtures rather than the rule.
|
*/

/** The rule set for one request class, for one field. */
function msgPhoneRules(string $requestClass, string $field): array
{
    return [$field => (new $requestClass)->rules()[$field]];
}

dataset('order request paths', [
    'website / phone orders' => [StoreOrderRequest::class, 'contact_phone'],
    'POS' => [StorePosOrderRequest::class, 'contact_phone'],
    'cart checkout' => [StoreOrderFromCartRequest::class, 'customer_phone'],
]);

it('refuses a number that could never be dialled', function (string $requestClass, string $field) {
    foreach (['0000000000', '1234567890', '123', 'none'] as $junk) {
        $validator = Validator::make([$field => $junk], msgPhoneRules($requestClass, $field));

        expect($validator->fails())->toBeTrue("{$junk} should have been refused");
    }
})->with('order request paths');

it('accepts a real Ghana number in any of its written forms', function (string $requestClass, string $field) {
    foreach (['0241234567', '+233241234567', '233241234567', '024 123 4567'] as $good) {
        $validator = Validator::make([$field => $good], msgPhoneRules($requestClass, $field));

        expect($validator->fails())->toBeFalse("{$good} should have been accepted");
    }
})->with('order request paths');

it('still accepts a dialleable but invented number', function (string $requestClass, string $field) {
    // Deliberate. Blocking these at the till would eventually refuse a real
    // customer standing at the counter, and would teach staff to invent less
    // obvious numbers — which is worse, because then nothing can spot them. The
    // `suspicious_customer_phone` rule follows up afterwards instead.
    $validator = Validator::make([$field => '0244444444'], msgPhoneRules($requestClass, $field));

    expect($validator->fails())->toBeFalse();
})->with('order request paths');

it('explains itself in words a cashier can act on', function () {
    $validator = Validator::make(
        ['contact_phone' => '123'],
        msgPhoneRules(StorePosOrderRequest::class, 'contact_phone'),
    );

    $validator->fails();

    expect($validator->errors()->first('contact_phone'))->toContain('0241234567');
});
