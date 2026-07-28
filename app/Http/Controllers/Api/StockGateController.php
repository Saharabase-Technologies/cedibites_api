<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\Stock\StockAvailabilityService;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the till may sell right now.
 *
 * The rule is enforced in PosOrderController::store — this is so the POS can
 * grey an item out as the cart is built instead of refusing after the customer
 * has decided and reached for their phone. Advisory by design: the client's copy
 * of the balances is always a moment stale, and two tills can sell the last
 * portion at the same time.
 */
class StockGateController extends Controller
{
    public function __construct(
        private readonly StockAvailabilityService $availability,
    ) {}

    /**
     * Per-option verdict for everything this branch serves.
     *
     * Options absent from the map have no recipe and are therefore always
     * sellable — the caller treats a missing key as yes.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branchId = (int) $validated['branch_id'];

        if (! $this->mayReadBranch($request, $branchId)) {
            return response()->error('Branch not found.', 404);
        }

        $optionIds = MenuItem::query()
            ->servedAt($branchId)
            ->join('menu_item_options', 'menu_item_options.menu_item_id', '=', 'menu_items.id')
            ->whereNull('menu_item_options.deleted_at')
            ->pluck('menu_item_options.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return response()->success([
            'branch_id' => $branchId,
            'sellable' => $this->availability->sellableMap($branchId, $optionIds),
        ]);
    }

    /**
     * Judge a specific basket. Aggregates across lines, because two dishes
     * sharing an ingredient can each pass alone and fail together.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_option_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $branchId = (int) $validated['branch_id'];

        if (! $this->mayReadBranch($request, $branchId)) {
            return response()->error('Branch not found.', 404);
        }

        $lines = array_map(fn (array $item) => [
            'option_id' => (int) $item['menu_item_option_id'],
            'quantity' => (float) $item['quantity'],
        ], $validated['items']);

        $result = $this->availability->check($branchId, $lines);

        return response()->success(array_merge($result->toArray(), [
            'can_override' => $request->user()?->can(\App\Enums\Permission::InventoryStockGateOverride->value) ?? false,
        ]));
    }

    private function mayReadBranch(Request $request, int $branchId): bool
    {
        $user = $request->user();

        if ($user?->hasAnyRole(['admin', 'tech_admin'])) {
            return true;
        }

        return $user?->employee
            ?->branches()
            ->where('branches.id', $branchId)
            ->exists() ?? false;
    }
}
