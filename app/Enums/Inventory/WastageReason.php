<?php

namespace App\Enums\Inventory;

/**
 * Why stock left without being sold.
 *
 * A fixed list, deliberately - free text produces a wastage report nobody can
 * total or trend. `Other` is the escape hatch and REQUIRES a note, so the
 * unusual case is still captured without diluting the list.
 *
 * The same list is used everywhere a loss is declared: a manual wastage, a
 * daily-closing variance, a rejected delivery, and a transfer shortfall written
 * off. One vocabulary, so the reports add up.
 */
enum WastageReason: string
{
    case Spoiled = 'spoiled';
    case Expired = 'expired';
    case Burnt = 'burnt';
    case DamagedInTransit = 'damaged_in_transit';
    case DamagedInStorage = 'damaged_in_storage';
    case Spillage = 'spillage';
    case Breakage = 'breakage';
    case Contamination = 'contamination';
    case PestDamage = 'pest_damage';
    case PreparationLoss = 'preparation_loss';
    case CustomerReturn = 'customer_return';
    case Theft = 'theft';
    case CountError = 'count_error';
    case TransferShortfall = 'transfer_shortfall';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spoiled => 'Spoiled / went bad',
            self::Expired => 'Expired',
            self::Burnt => 'Burnt in cooking',
            self::DamagedInTransit => 'Damaged in transit',
            self::DamagedInStorage => 'Damaged in storage',
            self::Spillage => 'Spillage',
            self::Breakage => 'Breakage',
            self::Contamination => 'Contamination',
            self::PestDamage => 'Pest / rodent damage',
            self::PreparationLoss => 'Preparation loss (trim, peel, bone)',
            self::CustomerReturn => 'Customer return',
            self::Theft => 'Theft / suspected theft',
            self::CountError => 'Count or entry error',
            self::TransferShortfall => 'Transfer shortfall written off',
            self::Other => 'Other',
        };
    }

    /** `Other` is meaningless without the note that explains it. */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * Reasons an operator may pick by hand. The rest are stamped by the system
     * when it classifies a loss it already knows the cause of.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $r) => $r !== self::TransferShortfall,
        ));
    }
}
