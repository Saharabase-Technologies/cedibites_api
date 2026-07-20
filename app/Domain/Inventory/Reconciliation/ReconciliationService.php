<?php

namespace App\Domain\Inventory\Reconciliation;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Enums\Inventory\ReconciliationStatus;
use App\Models\Inventory\ReconciliationCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock-take reconciliation — the loop the whole IMS builds toward. "Inventory is
 * basically accounting: whatever comes in, whatever comes out must cancel out."
 *
 * A cycle opens with a system-quantity snapshot (the ledger's expectation); the
 * warehouse manager counts everything physically; posting the adjustments writes
 * a `cycle_adjustment` movement for every non-zero variance, bringing the ledger
 * to the counted actual — the variance is "cancelled out", the books reset to
 * zero, and a new cycle can begin. Discrepancies whose value exceeds the location
 * threshold are flagged (the founder's red flag), but still reconciled.
 */
class ReconciliationService
{
    /**
     * Variance-value threshold (GHS): a counted discrepancy worth more than this
     * is flagged as a red flag for the warehouse manager (the founder's ₵500
     * rule). Still reconciled — the flag drives attention, not a block. A future
     * per-location IMS settings table can override this.
     */
    private const VARIANCE_THRESHOLD = 500.0;

    public function __construct(
        private readonly MovementPostingEngine $posting,
    ) {}

    /**
     * Open a cycle for a location with a system-quantity snapshot. Only one cycle
     * may be open per location at a time.
     */
    public function open(int $locationId, User $actor): ReconciliationCycle
    {
        $alreadyOpen = ReconciliationCycle::where('location_id', $locationId)
            ->where('status', ReconciliationStatus::Open->value)
            ->exists();
        if ($alreadyOpen) {
            throw new InventoryException('A reconciliation cycle is already open for this location. Close it before opening another.');
        }

        return DB::transaction(function () use ($locationId, $actor) {
            $cycle = ReconciliationCycle::create([
                'location_id' => $locationId,
                'status' => ReconciliationStatus::Open,
                'opened_by' => $actor->id,
                'opened_at' => now(),
            ]);

            $balances = DB::table('inventory_stock_balances as b')
                ->join('inventory_items as i', 'i.id', '=', 'b.item_id')
                ->where('b.location_id', $locationId)
                ->orderBy('i.name')
                ->get(['b.item_id', 'b.quantity', 'b.weighted_avg_cost', 'i.base_unit_id']);

            $cycle->lines()->createMany($balances->map(fn ($row) => [
                'item_id' => $row->item_id,
                'unit_id' => $row->base_unit_id,
                'system_qty' => round((float) $row->quantity, 4),
                'unit_cost' => $row->weighted_avg_cost !== null ? round((float) $row->weighted_avg_cost, 4) : null,
                'counted_qty' => null,
                'variance' => null,
                'variance_value' => null,
            ])->all());

            return $cycle;
        });
    }

    /**
     * Record physical counts. `$counts` is line_id => counted qty; only supplied
     * lines are touched. Variance + variance value are recomputed per line.
     *
     * @param  array<int,float>  $counts
     */
    public function saveCounts(ReconciliationCycle $cycle, array $counts): ReconciliationCycle
    {
        $this->assertOpen($cycle);

        return DB::transaction(function () use ($cycle, $counts) {
            $cycle->loadMissing('lines');

            foreach ($cycle->lines as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }
                $counted = round((float) $counts[$line->id], 4);
                if ($counted < 0) {
                    throw new InventoryException('Counted quantities cannot be negative.');
                }
                $this->applyCount($line, $counted);
                $line->save();
            }

            return $cycle;
        });
    }

    /**
     * Post the reconciliation: a `cycle_adjustment` movement for every non-zero
     * variance (bringing the ledger to the counted actual), then close the cycle.
     * Requires every line to be counted — a reconciliation is a full physical count.
     */
    public function post(ReconciliationCycle $cycle, User $actor, ?string $notes = null): ReconciliationCycle
    {
        $this->assertOpen($cycle);

        return DB::transaction(function () use ($cycle, $actor, $notes) {
            $cycle->loadMissing('lines');

            $uncounted = $cycle->lines->whereNull('counted_qty')->count();
            if ($uncounted > 0) {
                throw new InventoryException("Count every item before posting ({$uncounted} still uncounted).");
            }

            $threshold = self::VARIANCE_THRESHOLD;
            $netVarianceValue = 0.0;

            foreach ($cycle->lines as $line) {
                // Recompute defensively from the persisted count.
                $this->applyCount($line, (float) $line->counted_qty);
                $variance = (float) $line->variance;
                $varianceValue = (float) $line->variance_value;

                if ($variance !== 0.0) {
                    $movement = $this->posting->post([
                        'item_id' => $line->item_id,
                        'location_id' => $cycle->location_id,
                        'quantity' => $variance, // signed: counted − system → balance becomes counted
                        'movement_type' => 'cycle_adjustment',
                        'reference_type' => 'inventory_reconciliation',
                        'reference_id' => $cycle->id,
                        'unit_cost_at_time' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                        'user_id' => $actor->id,
                        'idempotency_key' => "cycle_adjustment:cycle:{$cycle->id}:item:{$line->item_id}",
                        'occurred_at' => now(),
                    ]);
                    $line->adjustment_movement_id = $movement->id;
                }

                $line->over_threshold = abs($varianceValue) > $threshold;
                $line->save();
                $netVarianceValue += $varianceValue;
            }

            $cycle->status = ReconciliationStatus::Closed;
            $cycle->closed_by = $actor->id;
            $cycle->closed_at = now();
            $cycle->net_variance_value = round($netVarianceValue, 4);
            $cycle->threshold_amount = $threshold;
            if ($notes !== null) {
                $cycle->notes = $notes;
            }
            $cycle->save();

            return $cycle;
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function applyCount($line, float $counted): void
    {
        $line->counted_qty = $counted;
        $variance = round($counted - (float) $line->system_qty, 4);
        $line->variance = $variance;
        $line->variance_value = round($variance * (float) ($line->unit_cost ?? 0), 4);
    }

    private function assertOpen(ReconciliationCycle $cycle): void
    {
        if (! $cycle->status->isOpen()) {
            throw new InventoryException('This reconciliation cycle is already closed.');
        }
    }
}
