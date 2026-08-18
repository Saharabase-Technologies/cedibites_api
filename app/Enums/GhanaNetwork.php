<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Which network a Ghanaian mobile number is on.
 *
 * Derived from the prefix rather than stored, because we never asked. Every
 * number we hold is already enough to know this, so targeting by network needs
 * no new data collection and works retroactively on the whole list.
 *
 * The caveat worth knowing: prefixes identify the network a number was *issued*
 * by. Ghana has had mobile number portability since 2011, so a ported number
 * reports its original network here, not its current one. There is no way to
 * tell from the number alone — only an HLR lookup would say, and that is a paid
 * per-number query. For campaign targeting the prefix is close enough; do not
 * present it as certainty.
 */
enum GhanaNetwork: string
{
    use HasEnumHelpers;

    case Mtn = 'mtn';
    case Telecel = 'telecel';
    case AirtelTigo = 'airteltigo';
    case Glo = 'glo';

    public function label(): string
    {
        return match ($this) {
            self::Mtn => 'MTN',
            // Renamed from Vodafone Ghana in 2023. The prefixes did not change.
            self::Telecel => 'Telecel',
            self::AirtelTigo => 'AirtelTigo',
            self::Glo => 'Glo',
        };
    }

    /**
     * The two digits after the country code that identify each network.
     *
     * @return array<int, string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::Mtn => ['24', '25', '53', '54', '55', '59'],
            self::Telecel => ['20', '50'],
            self::AirtelTigo => ['26', '27', '56', '57'],
            self::Glo => ['23', '28'],
        };
    }

    /**
     * The network for a phone number, or null if the prefix is unrecognised.
     *
     * Accepts anything the rest of the system holds — `+233241234567`,
     * `233241234567`, `0241234567` — because the audience is keyed by phone and
     * those three forms all appear across users and order contacts.
     */
    public static function forPhone(?string $phone): ?self
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        $prefix = match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '233') => substr($digits, 3, 2),
            strlen($digits) === 10 && str_starts_with($digits, '0') => substr($digits, 1, 2),
            strlen($digits) === 9 => substr($digits, 0, 2),
            default => null,
        };

        if ($prefix === null) {
            return null;
        }

        foreach (self::cases() as $network) {
            if (in_array($prefix, $network->prefixes(), true)) {
                return $network;
            }
        }

        return null;
    }
}
