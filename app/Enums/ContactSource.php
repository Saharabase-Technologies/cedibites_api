<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Which pool of numbers a campaign draws from.
 *
 * The two are a PARTITION, not two overlapping lists. Customers is everybody who
 * has bought from us; Supplementary is everybody we hold a number for who has
 * not. Nobody is in both, so the counts beside them add up and selecting both
 * reaches each person exactly once.
 *
 * That property is the reason Supplementary is defined by state rather than by
 * origin. "Imported contacts" would have been the obvious name and the wrong
 * one: an imported contact who has since ordered is a customer now, and counting
 * them on the imported side would have the two figures double-count the very
 * people the feature exists to track. A contact leaves this pool the moment they
 * order — see App\Services\Contacts\ContactConverter.
 *
 * The default is Customers alone, and that default is load-bearing. Contacts
 * arrive in the thousands and have bought nothing; folding them into "everyone"
 * would mean the day somebody uploads a list, every existing preset and every
 * saved audience quietly grows by that list. Reaching them has to be asked for.
 */
enum ContactSource: string
{
    use HasEnumHelpers;

    /** Anybody who has ordered, plus registered account holders. */
    case Customers = 'customers';

    /** Imported numbers that have never ordered. Not customers, and not counted as any. */
    case Supplementary = 'supplementary';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::Supplementary => 'Supplementary',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Customers => 'Everybody who has ordered from us, plus registered account holders. Anyone an imported list has won is already counted here.',
            self::Supplementary => 'Imported numbers that have never ordered. No behaviour filter will match them — they have no orders to filter on.',
        };
    }
}
