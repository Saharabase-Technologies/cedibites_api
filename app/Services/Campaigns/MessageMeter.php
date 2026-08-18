<?php

namespace App\Services\Campaigns;

/**
 * How much a message costs to say.
 *
 * SMS billing is a step function, not a slope. One segment is 160 GSM-7
 * characters; the moment a message needs two, the parts shrink to 153 each — so
 * 161 characters buys 306, not 320. Trimming characters saves nothing at 100 and
 * saves 100% of the send at 161.
 *
 * The other cliff is the alphabet. GSM-7 covers plain Latin text, a handful of
 * symbols, and some accented letters — é, à, ö, ñ and ü are all free. One
 * character outside it, though — a curly quote pasted out of Word, an em dash,
 * an emoji — re-encodes the *entire* message as UCS-2 and collapses the limit
 * from 160 to 70. A single ’ in place of ' can therefore triple the bill for
 * 28,000 people, which is why the offending characters are named in the result
 * rather than merely counted: the fix is to replace that character, and you
 * cannot replace what you cannot see.
 *
 * This is the single source of truth for the count. lib/sms/meter.ts mirrors it
 * so the counter can move as the operator types; the two are checked against
 * each other in MessageMeterTest.
 */
class MessageMeter
{
    /**
     * The GSM 03.38 basic set. Each of these costs one unit.
     *
     * The escape character (0x1B) is deliberately absent — it is not text, it is
     * the prefix that makes the extended characters below cost two.
     */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /** The extended set. Sent as an escape plus the character, so two units each. */
    private const GSM_EXTENDED = '^{}\\[~]|€';

    private const GSM_SINGLE = 160;

    private const GSM_MULTIPART = 153;

    private const UCS2_SINGLE = 70;

    private const UCS2_MULTIPART = 67;

    /**
     * @return array{
     *     characters: int,
     *     segments: int,
     *     encoding: string,
     *     remaining_in_segment: int,
     *     non_gsm_characters: array<int, string>
     * }
     */
    public function measure(string $message): array
    {
        $nonGsm = $this->nonGsmCharacters($message);
        $isUnicode = $nonGsm !== [];

        $characters = $isUnicode
            ? $this->utf16Units($message)
            : $this->gsmUnits($message);

        [$single, $multipart] = $isUnicode
            ? [self::UCS2_SINGLE, self::UCS2_MULTIPART]
            : [self::GSM_SINGLE, self::GSM_MULTIPART];

        $segments = match (true) {
            $characters === 0 => 0,
            $characters <= $single => 1,
            default => (int) ceil($characters / $multipart),
        };

        // What the operator actually wants to know: how much more can I write
        // before this costs another segment for everybody.
        $capacity = $segments <= 1 ? $single : $segments * $multipart;

        return [
            'characters' => $characters,
            'segments' => $segments,
            'encoding' => $isUnicode ? 'UCS_2' : 'GSM_7BIT',
            'remaining_in_segment' => $capacity - $characters,
            'non_gsm_characters' => $nonGsm,
        ];
    }

    /** Billed segments for one message. */
    public function segments(string $message): int
    {
        return $this->measure($message)['segments'];
    }

    /**
     * What sending this message to this many people is projected to cost, in GHS.
     *
     * A projection, not a price. Hubtel returns a real rate on the send
     * response; from the first authenticated campaign the actual figure on the
     * campaign row is measured rather than estimated.
     */
    public function estimateCost(string $message, int $recipients, ?float $ratePerSegment = null): float
    {
        $rate = $ratePerSegment ?? (float) config('campaigns.estimated_rate_per_segment', 0.05);

        return round($this->segments($message) * $recipients * $rate, 4);
    }

    /**
     * The characters that would force the whole message into UCS-2.
     *
     * Deduplicated and in the order they first appear, because the point is to
     * show the operator which character to replace — not to count how many times
     * they typed it.
     *
     * @return array<int, string>
     */
    private function nonGsmCharacters(string $message): array
    {
        $found = [];

        foreach (mb_str_split($message) as $char) {
            if ($this->isGsm($char)) {
                continue;
            }

            $found[$char] = true;
        }

        return array_keys($found);
    }

    /** Units in GSM-7, where the extended characters cost two. */
    private function gsmUnits(string $message): int
    {
        $units = 0;

        foreach (mb_str_split($message) as $char) {
            $units += mb_strpos(self::GSM_EXTENDED, $char) !== false ? 2 : 1;
        }

        return $units;
    }

    /**
     * UTF-16 code units, which is what UCS-2 billing counts.
     *
     * Not the same as the character count: anything above the BMP — most emoji —
     * is a surrogate pair and costs two.
     */
    private function utf16Units(string $message): int
    {
        return (int) (strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')) / 2);
    }

    private function isGsm(string $char): bool
    {
        return mb_strpos(self::GSM_BASIC, $char) !== false
            || mb_strpos(self::GSM_EXTENDED, $char) !== false;
    }
}
