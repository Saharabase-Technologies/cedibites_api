<?php

namespace App\Domain\Inventory\Movements\Engines;

/**
 * Weighted-average cost recalculation on receipt.
 *
 * new_avg = (old_qty * old_cost + incoming_qty * incoming_unit_cost) / (old_qty + incoming_qty)
 *
 * Issues, reversals, and zero/negative movements never change the average cost.
 */
class WeightedAverageCostCalculator
{
    public function next(
        float $currentQty,
        float $currentAvgCost,
        float $incomingQty,
        ?float $incomingUnitCost,
    ): float {
        if ($incomingQty <= 0 || $incomingUnitCost === null) {
            return round($currentAvgCost, 4);
        }

        $newQty = $currentQty + $incomingQty;
        if ($newQty <= 0) {
            return round($currentAvgCost, 4);
        }

        // Treat a negative/zero starting position as a fresh receipt at incoming cost.
        $baseQty = max($currentQty, 0);

        $value = ($baseQty * $currentAvgCost) + ($incomingQty * $incomingUnitCost);

        return round($value / ($baseQty + $incomingQty), 4);
    }
}
