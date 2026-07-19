<?php

namespace App\Domain\Inventory\Closing;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Enums\Inventory\DailyClosingStatus;
use App\Models\Inventory\DailyClosing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mandatory end-of-day stock count. Opening a closing snapshots the expected
 * quantity (ledger balance) for every item held at the location; the operator
 * then enters what was physically counted and completes it, locking in the
 * variance (counted − expected). Missed days are simply dates with no closing —
 * see calendar()/the variance report — and are never silently fabricated.
 */
class DailyClosingService
{
    /**
     * Open (or return the existing) closing for a location + business date, with
     * an expected-quantity snapshot from the current ledger balances.
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
     * Record the counted quantities. `$counts` is line_id => counted qty; only the
     * lines supplied are touched. Completing requires every line to be counted so
     * a "completed" closing is a genuine full count.
     *
     * @param  array<int,float>  $counts
     */
    public function saveCounts(DailyClosing $closing, array $counts, bool $complete, User $actor): DailyClosing
    {
        if (! $closing->status->isEditable()) {
            throw new InventoryException("This closing is already completed and can no longer be edited.");
        }

        return DB::transaction(function () use ($closing, $counts, $complete, $actor) {
            $closing->loadMissing('lines');

            foreach ($closing->lines as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }
                $counted = round((float) $counts[$line->id], 4);
                if ($counted < 0) {
                    throw new InventoryException('Counted quantities cannot be negative.');
                }
                $line->counted_qty = $counted;
                $line->variance = round($counted - (float) $line->expected_qty, 4);
                $line->save();
            }

            if ($complete) {
                $uncounted = $closing->lines()->whereNull('counted_qty')->count();
                if ($uncounted > 0) {
                    throw new InventoryException("Count every item before completing ({$uncounted} still uncounted), or save your progress instead.");
                }
                $closing->status = DailyClosingStatus::Completed;
                $closing->completed_by = $actor->id;
                $closing->completed_at = now();
                $closing->save();
            }

            return $closing;
        });
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
}
