<?php

namespace App\Domain\Inventory\Closing;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Movements\Engines\MovementPostingEngine;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\DailyClosingStatus;
use App\Enums\Inventory\WastageOrigin;
use App\Enums\Inventory\WastageReason;
use App\Models\Inventory\DailyClosing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mandatory end-of-day stock count - and the thing that makes tomorrow start
 * where tonight finished.
 *
 * The founder's requirement, in his words: "if as of yesterday the branch had
 * five boxes of can molds, then starting tomorrow the branch will start with
 * five boxes." A count that merely observes a discrepancy cannot deliver that.
 * So completing a count POSTS the difference to the ledger as a
 * `count_adjustment`, and the closing balance becomes literally what was
 * counted. Tomorrow's opening stock is last night's counted actual - never a
 * figure the ledger merely hoped for, and never yesterday's error carried
 * forward to be rediscovered tomorrow.
 *
 * Three properties hold this up:
 *
 *   BLIND. Expected quantities are withheld while a count is open (see
 *   DailyClosingResource). Showing the answer next to the input box turns a
 *   stock count into a copying exercise.
 *
 *   FRESH. Expected is re-read from the ledger at completion, not at opening. A
 *   count opened at 8am and finished at 9pm would otherwise be measured against
 *   a figure from before the day's trading.
 *
 *   NAMED. Shortfalls can carry a reason, which files them in the wastage report
 *   under what actually happened. Reasons are optional by design - a day must
 *   always be able to close - so a shortfall with no reason stays visibly
 *   unexplained rather than being quietly dressed up as something.
 */
class DailyClosingService
{
    public function __construct(
        private readonly MovementPostingEngine $posting,
        private readonly WastageService $wastage,
    ) {}

    /**
     * Open (or return the existing) closing for a location + business date.
     *
     * The expected quantities written here are provisional - a working snapshot,
     * refreshed at completion. They are never shown to the person counting.
     */
    public function open(int $locationId, string $date, User $actor): DailyClosing
    {
        $businessDate = Carbon::parse($date)->toDateString();
        if (Carbon::parse($businessDate)->isFuture()) {
            throw new InventoryException('A daily closing cannot be opened for a future date.');
        }

        $existing = DailyClosing::where('location_id', $locationId)
            ->whereDate('business_date', $businessDate)
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($locationId, $businessDate, $actor) {
            $closing = DailyClosing::create([
                'location_id' => $locationId,
                'business_date' => $businessDate,
                'status' => DailyClosingStatus::Open,
                'opened_by' => $actor->id,
            ]);

            $balances = DB::table('inventory_stock_balances as b')
                ->join('inventory_items as i', 'i.id', '=', 'b.item_id')
                ->where('b.location_id', $locationId)
                ->orderBy('i.name')
                ->get(['b.item_id', 'b.quantity', 'i.base_unit_id']);

            $closing->lines()->createMany($balances->map(fn ($row) => [
                'item_id' => $row->item_id,
                'unit_id' => $row->base_unit_id,
                'expected_qty' => round((float) $row->quantity, 4),
                'counted_qty' => null,
                'variance' => null,
            ])->all());

            return $closing;
        });
    }

    /**
     * Record the counted quantities, and on completion settle the day.
     *
     * `$counts` is line_id => ['counted_qty' => float, 'reason' => ?string,
     * 'reason_note' => ?string]; only the lines supplied are touched. Completing
     * requires every line counted, so a completed closing is a genuine full
     * count.
     *
     * @param  array<int,array{counted_qty?:float, reason?:string|null, reason_note?:string|null}|float>  $counts
     */
    public function saveCounts(DailyClosing $closing, array $counts, bool $complete, User $actor): DailyClosing
    {
        if (! $closing->status->isEditable()) {
            throw new InventoryException('This closing is already completed and can no longer be edited.');
        }

        return DB::transaction(function () use ($closing, $counts, $complete, $actor) {
            $closing->loadMissing('lines');

            foreach ($closing->lines as $line) {
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
                    $line->counted_qty = $counted;
                    $line->variance = round($counted - (float) $line->expected_qty, 4);
                }

                if (array_key_exists('reason', $entry)) {
                    $line->reason = $this->validateReason($entry['reason'], $entry['reason_note'] ?? null);
                    $line->reason_note = $line->reason !== null
                        ? (trim((string) ($entry['reason_note'] ?? '')) ?: null)
                        : null;
                }

                $line->save();
            }

            if ($complete) {
                $uncounted = $closing->lines()->whereNull('counted_qty')->count();
                if ($uncounted > 0) {
                    throw new InventoryException("Count every item before completing ({$uncounted} still uncounted), or save your progress instead.");
                }

                $this->settle($closing, $actor);
            }

            return $closing;
        });
    }

    /**
     * Close the day out: re-measure against the ledger as it stands right now,
     * post the difference so the books agree with the shelf, and file the
     * explained shortfalls as wastage.
     *
     * Called inside saveCounts' transaction.
     */
    private function settle(DailyClosing $closing, User $actor): void
    {
        $locationId = (int) $closing->location_id;
        $closing->loadMissing('lines');

        // Re-read the ledger now, not as it stood when the count was opened.
        $balances = DB::table('inventory_stock_balances')
            ->where('location_id', $locationId)
            ->whereIn('item_id', $closing->lines->pluck('item_id')->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('item_id');

        $reasoned = [];

        foreach ($closing->lines as $line) {
            $balance = $balances[$line->item_id] ?? null;
            $expected = $balance ? round((float) $balance->quantity, 4) : 0.0;
            $unitCost = $balance && $balance->weighted_avg_cost !== null
                ? (float) $balance->weighted_avg_cost
                : null;

            $counted = (float) $line->counted_qty;
            $variance = round($counted - $expected, 4);

            $line->expected_qty = $expected;
            $line->variance = $variance;

            if ($variance !== 0.0) {
                // Signed, so the balance lands exactly on what was counted.
                // THIS is what makes tomorrow open where tonight closed.
                $movement = $this->posting->post([
                    'item_id' => (int) $line->item_id,
                    'location_id' => $locationId,
                    'quantity' => $variance,
                    'movement_type' => 'count_adjustment',
                    'reference_type' => 'inventory_daily_closing',
                    'reference_id' => (int) $closing->id,
                    'unit_cost_at_time' => $unitCost,
                    'user_id' => $actor->id,
                    'idempotency_key' => "count_adjustment:closing:{$closing->id}:item:{$line->item_id}",
                    'occurred_at' => now(),
                ]);
                $line->adjustment_movement_id = $movement->id;
            }

            $line->save();

            // Only shortfalls are losses. A line counted OVER is stock found,
            // not stock wasted, and has its own conversation to answer.
            if ($variance < 0 && $line->reason !== null) {
                $reasoned[] = [
                    'item_id' => (int) $line->item_id,
                    'unit_id' => (int) $line->unit_id,
                    'quantity' => abs($variance),
                    'unit_cost' => $unitCost,
                    'reason' => $line->reason->value,
                    'reason_note' => $line->reason_note,
                ];
            }
        }

        // Classification only - the count adjustments above already moved the
        // stock. Posting here as well would write the same missing rice off
        // twice.
        $wastage = $this->wastage->classifyCountVariance(
            locationId: $locationId,
            lines: $reasoned,
            origin: WastageOrigin::DailyClosing,
            sourceType: 'inventory_daily_closing',
            sourceId: (int) $closing->id,
            actor: $actor,
            notes: "Explained shortfalls from the count of {$closing->business_date->toDateString()}.",
        );

        $closing->status = DailyClosingStatus::Completed;
        $closing->completed_by = $actor->id;
        $closing->completed_at = now();
        $closing->wastage_id = $wastage?->id;
        $closing->save();
    }

    /**
     * Calendar of closing coverage for a location across a date range. Every date
     * in the range is returned with whether a closing exists (and its status), so
     * missing dates render as "missed".
     *
     * @return array<int,array{date:string,status:string|null,id:int|null}>
     */
    public function calendar(int $locationId, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $closings = DailyClosing::where('location_id', $locationId)
            ->whereDate('business_date', '>=', $start->toDateString())
            ->whereDate('business_date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn ($c) => $c->business_date->toDateString());

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $closing = $closings->get($key);
            $days[] = [
                'date' => $key,
                'status' => $closing?->status->value,
                'id' => $closing?->id,
            ];
        }

        return $days;
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
}
