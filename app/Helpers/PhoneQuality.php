<?php

namespace App\Helpers;

/**
 * Tells a usable Ghana number from one typed only to get past the input field.
 *
 * Two separate questions, and conflating them is the mistake:
 *
 *  - `isWellFormed()` — could this be dialled? Enforced at the boundary as a
 *    validation rule, because a number that cannot be dialled has no business
 *    being saved at all.
 *  - `isSuspicious()` — is it dialleable but obviously invented? NOT enforced.
 *    `0244444444` is a legitimate number somebody could hold, and refusing it
 *    outright would eventually block a real customer at the till, mid-queue,
 *    with no way round it. So this one only feeds a rule that messages the staff
 *    member afterwards.
 *
 * The split matters: hard-blocking the second class would push staff to type
 * something *less* obviously fake, and we would lose the ability to spot it.
 */
class PhoneQuality
{
    /**
     * Ghana mobile in any of its written forms.
     *
     * A SUPERSET of `isValidGhanaPhone()` in the frontend's app/lib/phone.ts,
     * which does not accept a bare `233…` without the plus. The asymmetry is
     * deliberate and only safe in this direction: everything the browser lets
     * through is accepted here, so no input can ever pass validation on screen
     * and then 422 at the API — which reads to the user as the app being broken.
     * Being stricter than the client is the failure mode; being laxer is not.
     *
     * Bare `233…` is admitted because `PhoneHelper::normalize()` already treats
     * it as valid and Hubtel hands numbers back in exactly that shape. Refusing
     * a form our own normaliser accepts would be an inconsistency waiting to bite
     * an import or a callback.
     */
    private const FORMAT = '/^(\+?233|0)[2-9]\d{8}$/';

    public static function isWellFormed(?string $phone): bool
    {
        if ($phone === null) {
            return false;
        }

        return (bool) preg_match(self::FORMAT, preg_replace('/[\s\-()]/', '', $phone) ?? '');
    }

    /**
     * The nine national digits, or null when the number is not well-formed.
     */
    public static function nationalDigits(?string $phone): ?string
    {
        if (! self::isWellFormed($phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Both written forms collapse to the same nine digits: 0XXXXXXXXX drops
        // the trunk zero, 233XXXXXXXXX drops the country code.
        return str_starts_with($digits, '233') ? substr($digits, 3) : substr($digits, 1);
    }

    /**
     * Dialleable, but bearing the marks of a number invented at the counter.
     *
     * Returns the reason rather than a bare bool so the message sent to staff can
     * say which pattern was spotted — "the same digit nine times" lands
     * differently from a generic "looks fake", and is much harder to argue with.
     */
    public static function suspicionReason(?string $phone): ?string
    {
        $digits = self::nationalDigits($phone);

        if ($digits === null) {
            return null;
        }

        // 024 444 4444 — one digit held down.
        if (preg_match('/^(\d)\1{8}$/', $digits)) {
            return 'the same digit repeated nine times';
        }

        // The last six carry the information; a network prefix repeating is
        // ordinary, six identical digits after it is not.
        if (preg_match('/^\d{3}(\d)\1{5}$/', $digits)) {
            return 'the same digit repeated six times';
        }

        if (self::isSequentialRun($digits)) {
            return 'digits running straight up or down';
        }

        // 0242424242 — a two-digit pattern typed repeatedly.
        if (preg_match('/^(\d{2})\1{3}\d$/', $digits) || preg_match('/^(\d{3})\1{2}$/', $digits)) {
            return 'a short pattern typed over and over';
        }

        return null;
    }

    public static function isSuspicious(?string $phone): bool
    {
        return self::suspicionReason($phone) !== null;
    }

    /**
     * Every digit one step up, or one step down, from the last — 0123456789 and
     * its reverse. Wrapping (…89012…) counts: it is the same keyboard walk.
     */
    private static function isSequentialRun(string $digits): bool
    {
        foreach ([1, -1] as $step) {
            $runs = true;

            for ($i = 1, $len = strlen($digits); $i < $len; $i++) {
                $expected = ((int) $digits[$i - 1] + $step + 10) % 10;

                if ((int) $digits[$i] !== $expected) {
                    $runs = false;
                    break;
                }
            }

            if ($runs) {
                return true;
            }
        }

        return false;
    }
}
