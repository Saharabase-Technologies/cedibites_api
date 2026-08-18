<?php

use App\Services\Campaigns\MessageMeter;

/*
 * The meter decides what a campaign costs, so every boundary here is money.
 *
 * SMS billing is a step function: 160 GSM-7 characters is one segment, and 161
 * is two — not two-and-a-bit, because concatenated parts shrink to 153 each. At
 * 28,000 recipients, crossing that line by one character doubles the bill.
 */

beforeEach(function () {
    $this->meter = new MessageMeter;
    config(['campaigns.estimated_rate_per_segment' => 0.05]);
});

describe('GSM-7 counting', function () {
    it('counts an empty message as no segments at all', function () {
        expect($this->meter->measure(''))
            ->characters->toBe(0)
            ->segments->toBe(0);
    });

    it('fits 160 characters in one segment', function () {
        $result = $this->meter->measure(str_repeat('a', 160));

        expect($result['characters'])->toBe(160)
            ->and($result['segments'])->toBe(1)
            ->and($result['remaining_in_segment'])->toBe(0);
    });

    /*
     * The step, and the whole reason the shortener exists. One more character
     * does not buy a little more room — it buys a second whole segment, and the
     * pair are 153 each rather than 160.
     */
    it('spills into two segments at 161 characters', function () {
        $result = $this->meter->measure(str_repeat('a', 161));

        expect($result['segments'])->toBe(2)
            // 306 capacity, not 320.
            ->and($result['remaining_in_segment'])->toBe(145);
    });

    it('starts a third segment at 307', function () {
        expect($this->meter->segments(str_repeat('a', 306)))->toBe(2)
            ->and($this->meter->segments(str_repeat('a', 307)))->toBe(3);
    });

    /*
     * The GSM extended characters are sent as an escape plus the character, so
     * each one costs two of the 160. A message of 80 curly braces is full.
     */
    it('charges two units for an extended character', function () {
        expect($this->meter->measure('{')['characters'])->toBe(2)
            ->and($this->meter->segments(str_repeat('{', 80)))->toBe(1)
            ->and($this->meter->segments(str_repeat('{', 81)))->toBe(2);
    });

    it('accepts the Ghanaian cedi text and pounds without leaving GSM', function () {
        expect($this->meter->measure('GHS 20 off at East Legon & Spintex!')['encoding'])
            ->toBe('GSM_7BIT');
    });
});

describe('the Unicode cliff', function () {
    /*
     * This is the expensive mistake. One character outside GSM-7 — a curly
     * quote pasted out of Word — re-encodes the *entire* message and drops the
     * limit from 160 to 70. A message that was one segment becomes three, and
     * nothing on screen looks wrong.
     */
    it('collapses the whole message to 70 for one curly apostrophe', function () {
        $plain = str_repeat('a', 150);
        $curly = str_repeat('a', 149).'’';

        expect($this->meter->segments($plain))->toBe(1)
            ->and($this->meter->measure($curly)['encoding'])->toBe('UCS_2')
            ->and($this->meter->segments($curly))->toBe(3);
    });

    /* Naming the character is the point — counting it does not help anyone fix it. */
    it('names the characters that forced UCS-2, without repeating them', function () {
        $result = $this->meter->measure('Don’t miss it — really 🎉 don’t');

        expect($result['non_gsm_characters'])->toBe(['’', '—', '🎉']);
    });

    it('fits 70 in one segment and spills at 71', function () {
        expect($this->meter->segments(str_repeat('’', 70)))->toBe(1)
            ->and($this->meter->segments(str_repeat('’', 71)))->toBe(2);
    });

    /*
     * Not every accent is expensive. GSM-7 carries é, à, ö, ñ, ü and a handful
     * more, so a message using them stays at 160 — worth knowing before somebody
     * strips accents out of copy to save money they were never spending.
     */
    it('keeps the accented letters GSM-7 actually carries', function () {
        expect($this->meter->measure('Café à Öñü')['encoding'])->toBe('GSM_7BIT')
            ->and($this->meter->segments(str_repeat('é', 160)))->toBe(1);
    });

    /* Most emoji are surrogate pairs, so they cost two of the seventy. */
    it('charges an astral emoji as two units', function () {
        expect($this->meter->measure('🎉')['characters'])->toBe(2);
    });
});

describe('cost', function () {
    it('multiplies segments by recipients by the rate', function () {
        // 161 characters = 2 segments. 2 x 1000 x 0.05
        expect($this->meter->estimateCost(str_repeat('a', 161), 1000))->toBe(100.0);
    });

    /*
     * The case for the shortener, in one assertion. The same promo with a full
     * campaign URL costs twice what it does with a short link, and the only
     * difference is 55 characters nobody reads.
     */
    it('halves the bill when a long URL becomes a short link', function () {
        $long = 'CediBites: Friday treat! 20% off all jollof today only at East Legon & '
            .'Spintex. Order: app.cedibites.com/promo/friday-special?utm_source=sms&utm_campaign=aug-friday';
        $short = 'CediBites: Friday treat! 20% off all jollof today only at East Legon & '
            .'Spintex. Order: cedibites.com/r/A7X9Kp';

        expect($this->meter->segments($long))->toBe(2)
            ->and($this->meter->segments($short))->toBe(1)
            ->and($this->meter->estimateCost($long, 28000))->toBe(2800.0)
            ->and($this->meter->estimateCost($short, 28000))->toBe(1400.0);
    });
});
