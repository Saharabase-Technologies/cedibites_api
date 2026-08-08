<?php

namespace App\Services\Contacts;

use App\Helpers\PhoneHelper;

/**
 * A Ghana mobile number out of a spreadsheet cell, or nothing.
 *
 * Separate from PhoneHelper on purpose. PhoneHelper returns its input unchanged
 * when it cannot normalise, which is right for a form field that has already
 * been validated — but an importer needs a yes-or-no answer for every one of
 * 28,000 cells, and "returns the string you gave it" is not one.
 *
 * The rest of it is undoing what spreadsheets do to phone numbers. Excel treats
 * 0241234567 as a number, drops the leading zero, and hands back 241234567;
 * paste a column of those into a delivery system unchanged and every message
 * fails. That single transformation is the most common reason an imported list
 * is unusable, so it is handled here rather than left for someone to notice.
 */
class PhoneNormaliser
{
    /** Strict Ghana mobile: +233 followed by nine digits starting 2–9. */
    private const PATTERN = '/^\+233[2-9]\d{8}$/';

    /**
     * @return string|null +233XXXXXXXXX, or null when the cell is not one
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // Excel writes long digit strings as 2.33241E+11 once the column is
        // wide enough. The digits are gone by then — this cannot be recovered,
        // only refused, and refusing loudly is what tells the operator to
        // re-export the column as text.
        if (stripos($value, 'e+') !== false) {
            return null;
        }

        // Strip the punctuation people write numbers with: +233 (24) 123-4567,
        // 024 123 4567, '0241234567 (the leading apostrophe Excel adds to force
        // text). Everything but digits and a leading plus.
        $value = preg_replace('/[^\d+]/', '', $value) ?? '';
        $value = preg_replace('/(?!^)\+/', '', $value) ?? '';

        if ($value === '') {
            return null;
        }

        // 00233… — the international prefix as dialled from a landline.
        if (str_starts_with($value, '00233')) {
            $value = '+'.substr($value, 2);
        }

        /*
         * Nine digits starting 2–9 is a Ghana mobile with its leading zero eaten
         * by a spreadsheet. Only applied to this exact shape: it is unambiguous,
         * because no other length or leading digit could be one.
         */
        if (preg_match('/^[2-9]\d{8}$/', $value)) {
            $value = '0'.$value;
        }

        $normalised = PhoneHelper::normalize($value);

        return preg_match(self::PATTERN, $normalised) ? $normalised : null;
    }

    public static function isValid(string $phone): bool
    {
        return (bool) preg_match(self::PATTERN, $phone);
    }
}
