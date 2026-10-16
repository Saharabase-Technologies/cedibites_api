<?php

namespace App\Services\Bolt;

use App\Services\SystemSettingService;

/**
 * What Bolt keeps of an order, and what it takes it from.
 *
 * Bolt's commission is 30% of the food (the owner, 2026-10-14). Bolt's own
 * reports do not say whether it is taken from what the customer paid or from
 * the listed price before a promo, so the admin sets that here once Bolt's
 * payout statement shows it. Every order keeps the rate and base it was
 * placed under, so changing either never rewrites an old order.
 */
class BoltSettings
{
    /** Taken from what the customer paid, after any Bolt promo. */
    public const BASE_PAID = 'paid';

    /** Taken from Bolt's listed price, before any promo. */
    public const BASE_LISTED = 'listed';

    public const BASES = [self::BASE_PAID, self::BASE_LISTED];

    public const DEFAULT_PERCENT = 30.0;

    private const KEY_PERCENT = 'bolt_commission_percent';

    private const KEY_BASE = 'bolt_commission_base';

    public function __construct(private SystemSettingService $settings) {}

    public function commissionPercent(): float
    {
        $value = $this->settings->get(self::KEY_PERCENT);

        return is_numeric($value) ? (float) $value : self::DEFAULT_PERCENT;
    }

    public function commissionBase(): string
    {
        $value = $this->settings->get(self::KEY_BASE);

        return in_array($value, self::BASES, true) ? $value : self::BASE_PAID;
    }

    public function update(float $percent, string $base): void
    {
        $this->settings->set(self::KEY_PERCENT, (string) round($percent, 2), 'string');
        $this->settings->set(self::KEY_BASE, $base, 'string');
    }

    /** @return array{commission_percent: float, commission_base: string} */
    public function toArray(): array
    {
        return [
            'commission_percent' => $this->commissionPercent(),
            'commission_base' => $this->commissionBase(),
        ];
    }
}
