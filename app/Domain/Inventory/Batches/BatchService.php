<?php

namespace App\Domain\Inventory\Batches;

use App\Models\Inventory\Batch;
use App\Models\Inventory\Item;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expiry/FEFO batch tracking. Expiry-tracked items hold their stock as dated
 * batches; stock-out movements consume the soonest-expiring batch first.
 *
 * `allocate()` and `restore()` mutate remaining_qty and MUST run inside the same
 * DB transaction as the movement posting they accompany.
 */
class BatchService
{
    /**
     * Create a batch for a received lot — only for expiry-tracked items.
     */
    public function recordReceipt(
        Item $item,
        int $locationId,
        float $qty,
        float $unitCost,
        ?string $expiryDate,
        ?int $purchaseItemId,
        \DateTimeInterface|string|null $receivedAt = null,
    ): ?Batch {
        if (! $item->expiry_tracked) {
            return null;
        }

        return Batch::create([
            'item_id' => $item->id,
            'location_id' => $locationId,
            'purchase_item_id' => $purchaseItemId,
            'received_qty' => $qty,
            'remaining_qty' => $qty,
            'unit_cost' => $unitCost,
            'expiry_date' => $expiryDate ?: null,
            'received_at' => $receivedAt ? Carbon::parse($receivedAt) : now(),
        ]);
    }

    /**
     * Allocate `qty` of an item across its open batches, soonest expiry first,
     * decrementing remaining_qty. Returns one entry per source (batch or, for the
     * uncovered remainder / untracked items, a null batch).
     *
     * @return array<int, array{batch_id:int|null, qty:float, unit_cost:float|null}>
     */
    public function allocate(int $itemId, int $locationId, float $qty): array
    {
        $qty = round($qty, 4);
        if ($qty <= 0) {
            return [];
        }

        $tracked = (bool) Item::whereKey($itemId)->value('expiry_tracked');
        if (! $tracked) {
            return [['batch_id' => null, 'qty' => $qty, 'unit_cost' => null]];
        }

        $batches = Batch::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('expiry_date IS NULL') // dated batches first
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $allocations = [];
        $outstanding = $qty;

        foreach ($batches as $batch) {
            if ($outstanding <= 0) {
                break;
            }
            $take = min((float) $batch->remaining_qty, $outstanding);
            if ($take <= 0) {
                continue;
            }
            $batch->remaining_qty = round((float) $batch->remaining_qty - $take, 4);
            $batch->save();

            $allocations[] = ['batch_id' => $batch->id, 'qty' => round($take, 4), 'unit_cost' => (float) $batch->unit_cost];
            $outstanding = round($outstanding - $take, 4);
        }

        // Shortfall (no/insufficient batch coverage) → null-batch remainder so the
        // movement still reflects the full quantity (balance may go negative).
        if ($outstanding > 0) {
            $allocations[] = ['batch_id' => null, 'qty' => $outstanding, 'unit_cost' => null];
        }

        return $allocations;
    }

    /**
     * Add quantity back to a batch (used when reversing a consumption/sale).
     */
    public function restore(?int $batchId, float $qty): void
    {
        if ($batchId === null || $qty <= 0) {
            return;
        }
        DB::table('inventory_batches')->where('id', $batchId)->increment('remaining_qty', $qty);
    }
}
