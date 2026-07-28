<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Domain\Inventory\Stock\StockAvailabilityService;
use App\Enums\Permission;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * No stock, no sale.
 *
 * Shared by every path that turns a basket into an order, because there is more
 * than one and they do not look alike. The terminal creates a checkout session
 * (CheckoutSessionController::posStore) and confirms it; PosOrderController
 * writes an order directly. Gating one and not the other is how a sale of 23
 * portions went through against a balance of 6 while the gate was "live" — so
 * the check lives here, once, and both call it.
 */
trait RefusesShortStock
{
    /**
     * Refuse the sale when the branch cannot make it — 422 naming the
     * ingredients that fell short, never a bare "out of stock".
     *
     * Returns null when the sale may proceed, which includes every case the
     * check could not judge: a branch with no inventory location, or dishes with
     * no recipe. Refusing on a configuration gap would stop a branch trading
     * over a data problem, and both gaps are surfaced elsewhere.
     *
     * A holder of inventory.stock_gate.override may pass `override_stock_gate`
     * with a reason — for when the ledger is wrong rather than the shelf empty,
     * such as a delivery that arrived and has not been recorded yet. Logged
     * either way, so overrides are countable afterwards.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function refuseIfShort(Request $request, int $branchId, array $items, Employee $employee): ?JsonResponse
    {
        $lines = [];
        foreach ($items as $item) {
            if (! empty($item['menu_item_option_id'])) {
                $lines[] = [
                    'option_id' => (int) $item['menu_item_option_id'],
                    'quantity' => (float) ($item['quantity'] ?? 1),
                ];
            }
        }

        if ($lines === []) {
            return null;
        }

        $result = app(StockAvailabilityService::class)->check($branchId, $lines);

        if ($result->canSell()) {
            return null;
        }

        $user = $request->user();
        $mayOverride = $user?->can(Permission::InventoryStockGateOverride->value) ?? false;

        if ($request->boolean('override_stock_gate') && $mayOverride) {
            activity('inventory')
                ->causedBy($user)
                ->event('stock_gate_overridden')
                ->withProperties([
                    'branch_id' => $branchId,
                    'employee_id' => $employee->id,
                    'reason' => $request->input('override_reason'),
                    'shortfalls' => $result->shortfalls,
                ])
                ->log('Sold past the stock gate: '.$result->message());

            return null;
        }

        return response()->json([
            'message' => $result->message(),
            // Both spellings on purpose. `code` is what the frontend's ApiError
            // preserves; `error` is the shape the rest of this API uses. Sending
            // one and reading the other is how the till fell back to a toast.
            'code' => 'insufficient_stock',
            'error' => 'insufficient_stock',
            'shortfalls' => $result->shortfalls,
            'can_override' => $mayOverride,
        ], 422);
    }
}
