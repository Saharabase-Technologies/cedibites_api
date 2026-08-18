<?php

use App\Helpers\PhoneQuality;

/*
|--------------------------------------------------------------------------
| Telling a real number from one typed to get past the field
|--------------------------------------------------------------------------
|
| Two separate questions, and the split is the whole design. Malformed is
| refused at the boundary. Dialleable-but-invented is ACCEPTED and reported
| afterwards, because refusing it would eventually block a real customer at the
| counter — and would teach staff to invent less obvious numbers, which is worse
| than the problem, since then nothing can spot them.
|
*/

it('accepts the forms a Ghana number is actually written in', function (string $phone) {
    expect(PhoneQuality::isWellFormed($phone))->toBeTrue();
})->with([
    '0241234567',
    '+233241234567',
    '233241234567',
    '024 123 4567',
    '024-123-4567',
    '0501234567',
    '0201234567',
]);

it('refuses what could never be dialled', function (?string $phone) {
    expect(PhoneQuality::isWellFormed($phone))->toBeFalse();
})->with([
    '1234567890',   // no Ghana prefix
    '024123456',    // one digit short
    '02412345678',  // one digit too many
    '0141234567',   // 1 is not a mobile prefix
    'not a phone',
    '',
    null,
]);

it('reduces both written forms to the same nine digits', function () {
    expect(PhoneQuality::nationalDigits('0241234567'))
        ->toBe(PhoneQuality::nationalDigits('+233241234567'));
});

it('spots a number that was obviously invented', function (string $phone) {
    // Well-formed — which is the point. Format alone cannot catch these.
    expect(PhoneQuality::isWellFormed($phone))->toBeTrue()
        ->and(PhoneQuality::isSuspicious($phone))->toBeTrue();
})->with([
    '0244444444',   // one digit held down
    '0234567890',   // a straight run
    '0242424242',   // a two-digit pattern
    '0987654321',   // a run downwards
]);

it('leaves an ordinary number alone', function (string $phone) {
    expect(PhoneQuality::isSuspicious($phone))->toBeFalse();
})->with([
    '0241234568',
    '0209837462',
    '0553019284',
    '+233247516382',
]);

it('says which pattern it spotted, so the message can be specific', function () {
    expect(PhoneQuality::suspicionReason('0244444444'))->toContain('repeated')
        ->and(PhoneQuality::suspicionReason('0234567890'))->toContain('running');
});

it('has no opinion about a number that is not well-formed', function () {
    // Malformed is the validator's business. Answering here too would mean two
    // places deciding the same thing and eventually disagreeing.
    expect(PhoneQuality::suspicionReason('garbage'))->toBeNull();
});
