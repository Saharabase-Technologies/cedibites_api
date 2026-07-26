<?php

namespace App\Domain\Inventory\Reconciliation;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\ReconciliationStatus;
use App\Enums\Inventory\WastageOrigin;
use App\Enums\Inventory\WastageReason;
use App\Models\Inventory\ReconciliationCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock-take reconciliation - the loop the whole IMS builds toward. "Inventory is
 * basically accounting: whatever comes in, whatever comes out must cancel out."
 *
 * A cycle opens with a system-quantity snapshot (the ledger's expectation); the
 * warehouse manager counts everything physically; posting the adjustments writes
 * a `cycle_adjustment` movement for every non-zero variance, bringing the ledger
 * to the counted actual - the variance is "cancelled out", the books reset to
 * zero, and a new cycle can begin. Discrepancies whose value exceeds the location
 * threshold are flagged (the founder's red flag), but still reconciled.
 */
class ReconciliationService
{
    /**
     * The variance-value threshold (GHS, the founder's ₵500 rule) is shared with
     * wastage and read live from `WastageService::threshold()` at posting time -
     * never captured as a constant. The admin can change it, and a stock-take
     * flagging against last month's figure is worse than not flagging at all.
     * Over-threshold lines are still reconciled; the flag drives attention, not
     * a block.
     */
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly WastageService $wastage,
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
     * Record physical counts. `$counts` is line_id => counted qty, or a shape
     * carrying the reason alongside it; only supplied lines are touched.
     * Variance + variance value are recomputed per line.
     *
     * A reason belongs here for the same reason it belongs on a daily count: a
     * stock-take that produces "200 expected, 190 counted" and stops has told
     * nobody anything they can act on.
     *
     * @param  array<int,array{counted_qty?:float, reason?:string|null, reason_note?:string|null}|float>  $counts
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

                $entry = $counts[$line->id];
                $entry = is_array($entry) ? $entry : ['counted_qty' => $entry];

                if (array_key_exists('counted_qty', $entry) && $entry['counted_qty'] !== null) {
                    $counted = round((float) $entry['counted_qty'], 4);
                    if ($counted < 0) {
                        throw new InventoryException('Counted quantities cannot be negative.');
                    }
                    $this->applyCount($line, $counted);
                }

                if (array_key_exists('reason', $entry)) {
                    $line->reason = $this->validateReason($entry['reason'], $entry['reason_note'] ?? null);
                    $line->reason_note = $line->reason !== null
                        ? (trim((string) ($entry['reason_note'] ?? '')) ?: null)
                        : null;
                }

                $line->save();
            }

            return $cycle;
        });
    }

    /**
     * Post the reconciliation: a `cycle_adjustment` movement for every non-zero
     * variance (bringing the ledger to the counted actual), then close the cycle.
     * Requires every line to be counted - a reconciliation is a full physical count.
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

            $threshold = WastageService::threshold();
            $netVarianceValue = 0.0;
            $reasoned = [];

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

                // Only shortfalls are losses; stock found is a different
                // conversation. Explained ones go into the wastage report.
                if ($variance < 0 && $line->reason !== null) {
                    $reasoned[] = [
                        'item_id' => (int) $line->item_id,
                        'unit_id' => (int) $line->unit_id,
                        'quantity' => abs($variance),
                        'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
                        'reason' => $line->reason->value,
                        'reason_note' => $line->reason_note,
                    ];
                }
            }

            // Classification only - the cycle adjustments above already brought
            // the ledger to the counted actual.
            $wastage = $this->wastage->classifyCountVariance(
                locationId: (int) $cycle->location_id,
                lines: $reasoned,
                origin: WastageOrigin::Reconciliation,
                sourceType: 'inventory_reconciliation',
                sourceId: (int) $cycle->id,
                actor: $actor,
                notes: 'Explained shortfalls from stock-take.',
            );
            $cycle->wastage_id = $wastage?->id;

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

    private function validateReason(?string $reason, ?string $note): ?WastageReason
    {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        $parsed = WastageReason::tryFrom($reason);
        if ($parsed === null) {
            throw new InventoryException("'{$reason}' is not a wastage reason.");
        }
        if ($parsed->requiresNote() && trim((string) $note) === '') {
            throw new InventoryException('Choosing "Other" means saying what happened - add a note.');
        }

        return $parsed;
    }

    private function assertOpen(ReconciliationCycle $cycle): void
    {
        if (! $cycle->status->isOpen()) {
            throw new InventoryException('This reconciliation cycle is already closed.');
        }
    }
}
