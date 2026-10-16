<?php

namespace App\Services\Bolt;

/**
 * The sums behind a Bolt order.
 *
 * A Bolt order is written at Bolt's listed prices. A Bolt promo is the
 * order's discount, because we pay for it. What the customer paid is the
 * order's total. Bolt's cut is worked once per order, rounded half up to the
 * pesewa, and what we keep is the total less that cut.
 */
class BoltPricing
{
    public const SOURCE = 'bolt';

    public const PAYMENT_METHOD = 'bolt';

    public function __construct(private BoltSettings $settings) {}

    /** The promo we pay for on one line: its listed total times the percent. */
    public function linePromo(float $lineListed, float|string|null $percent): float
    {
        $percent = (float) ($percent ?? 0);

        if ($percent <= 0) {
            return 0.0;
        }

        return round($lineListed * min($percent, 100) / 100, 2);
    }

    /**
     * Bolt's cut of one order, as the settings stand now.
     *
     * @return array{amount: float, rate: float, base: string}
     */
    public function commission(float $listed, float $paid): array
    {
        $rate = $this->settings->commissionPercent();
        $base = $this->settings->commissionBase();
        $from = $base === BoltSettings::BASE_LISTED ? $listed : $paid;

        return [
            'amount' => round($from * $rate / 100, 2),
            'rate' => $rate,
            'base' => $base,
        ];
    }
}
