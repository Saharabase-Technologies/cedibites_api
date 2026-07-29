<?php

namespace App\Domain\Inventory\Stock;

use App\Models\Inventory\Location;
use App\Models\Inventory\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Can this branch actually make this order?
 *
 * "If there's no stock, then there can be no sale." Deduction used to be
 * fire-and-forget: the balance went negative, an alert was raised, and the sale
 * went through regardless. This is the check that stops it.
 *
 * Four things it deliberately will NOT do:
 *
 *   1. Block when it cannot judge. A branch with no inventory location, or a
 *      dish with no recipe, produces no verdict — and no verdict must never mean
 *      "refuse". Stopping a branch from trading because of a configuration gap
 *      is worse than the gap. Both cases are already visible: a location-less
 *      branch raises misrouted_deduction on every sale, and a recipe-less dish
 *      shows up in the IMS recipe coverage report.
 *
 *   2. Treat an ingredient the location has never tracked as a balance of zero.
 *      This is the same rule as (1), for the gap it did not anticipate. A stock
 *      balance row is created the first time an item is counted or received at a
 *      location, so no row means "we have never counted this here" — not "we
 *      have none". Reading the absent row as 0.0 refused 55 of Test Branch's 59
 *      options on 2026-07-29, every one of them for a missing row rather than a
 *      real shortage: the top blockers were serviettes, carrier bags and
 *      seasoning cubes, which no one would call being out of food. A branch part
 *      way through IMS onboarding could not trade at all, and the cashier was
 *      told "out of stock" about things sitting in front of them. A row holding
 *      0 is a real zero and still refuses. See `inventory:stock-coverage`.
 *
 *   3. Judge add-ons. They carry no recipe and deduct nothing, so they can never
 *      short anything. Stated rather than silently true.
 *
 *   4. Name "stock" as the problem. A cashier cannot act on "out of stock"; they
 *      can act on "no chicken". Every shortfall names the ingredient.
 *
 * The check aggregates demand across the whole cart before comparing, because
 * two dishes sharing rice can each pass alone and fail together.
 */
class StockAvailabilityService
{
    /**
     * @param  list<array{option_id: int, quantity: int|float}>  $lines
     */
    public function check(?int $branchId, array $lines): StockCheckResult
    {
        $location = $branchId ? $this->locationFor($branchId) : null;

        if (! $location) {
            return StockCheckResult::unjudged('This branch has no inventory location, so stock was not checked.');
        }

        [$demand, $judgedAny] = $this->aggregateDemand($lines, $branchId);

        if (! $judgedAny) {
            return StockCheckResult::unjudged('No recipe covers these items, so stock was not checked.');
        }

        if ($demand === []) {
            return StockCheckResult::ok();
        }

        return new StockCheckResult(
            shortfalls: $this->shortfalls($demand, $location->id),
            judged: true,
        );
    }

    /**
     * Which of a branch's options can be made at least once right now.
     *
     * Feeds the POS so an item is greyed out as the cart is built, rather than
     * refused after the customer has committed. Options with no recipe are
     * absent from the map — the caller treats a missing key as sellable.
     *
     * @param  list<int>  $optionIds
     * @return array<int, bool> option id => can make one
     */
    public function sellableMap(?int $branchId, array $optionIds): array
    {
        $location = $branchId ? $this->locationFor($branchId) : null;

        if (! $location || $optionIds === []) {
            return [];
        }

        $balances = $this->balancesAt($location->id);
        $map = [];

        foreach ($optionIds as $optionId) {
            $recipe = $this->resolveRecipe($optionId, $branchId);

            if (! $recipe) {
                continue; // no recipe → nothing to judge → sellable
            }

            $yield = max((float) $recipe->yield_qty, 0.0001);
            $canMake = true;

            foreach ($recipe->ingredients as $ingredient) {
                $itemId = (int) $ingredient->item_id;

                // No balance row is not a balance of zero. See untracked().
                if (! array_key_exists($itemId, $balances)) {
                    continue;
                }

                $need = (float) $ingredient->quantity / $yield;
                if ($balances[$itemId] < $need) {
                    $canMake = false;
                    break;
                }
            }

            $map[$optionId] = $canMake;
        }

        return $map;
    }

    /**
     * Total ingredient demand for the cart, and whether any line was judgeable.
     *
     * @param  list<array{option_id: int, quantity: int|float}>  $lines
     * @return array{0: array<int, float>, 1: bool}
     */
    private function aggregateDemand(array $lines, ?int $branchId): array
    {
        $demand = [];
        $judgedAny = false;

        foreach ($lines as $line) {
            $optionId = (int) ($line['option_id'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($optionId <= 0 || $quantity <= 0) {
                continue;
            }

            $recipe = $this->resolveRecipe($optionId, $branchId);

            if (! $recipe) {
                continue; // add-on, or a dish with no BOM yet
            }

            $judgedAny = true;
            $portions = $quantity / max((float) $recipe->yield_qty, 0.0001);

            foreach ($recipe->ingredients as $ingredient) {
                $itemId = (int) $ingredient->item_id;
                $demand[$itemId] = ($demand[$itemId] ?? 0) + ((float) $ingredient->quantity * $portions);
            }
        }

        return [$demand, $judgedAny];
    }

    /**
     * @param  array<int, float>  $demand
     * @return list<array{item_id: int, item_name: string, unit: string|null, required: float, available: float}>
     */
    private function shortfalls(array $demand, int $locationId): array
    {
        $balances = $this->balancesAt($locationId);

        $names = DB::table('inventory_items')
            ->leftJoin('inventory_units', 'inventory_items.base_unit_id', '=', 'inventory_units.id')
            ->whereIn('inventory_items.id', array_keys($demand))
            ->get(['inventory_items.id', 'inventory_items.name', 'inventory_units.symbol'])
            ->keyBy('id');

        $short = [];

        foreach ($demand as $itemId => $required) {
            // No balance row is not a balance of zero. See untracked().
            if (! array_key_exists($itemId, $balances)) {
                continue;
            }

            $available = $balances[$itemId];

            if ($available >= $required) {
                continue;
            }

            $short[] = [
                'item_id' => $itemId,
                'item_name' => $names[$itemId]->name ?? "Item #{$itemId}",
                'unit' => $names[$itemId]->symbol ?? null,
                'required' => round($required, 4),
                'available' => round($available, 4),
            ];
        }

        return $short;
    }

    /**
     * @return array<int, float> item id => quantity on hand
     */
    private function balancesAt(int $locationId): array
    {
        return DB::table('inventory_stock_balances')
            ->where('location_id', $locationId)
            ->pluck('quantity', 'item_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    /**
     * Branch-specific recipe first, then the global default; locked beats draft.
     * Mirrors RecipeDeductionService so the check and the deduction can never
     * disagree about which BOM applies.
     */
    private function resolveRecipe(int $optionId, ?int $branchId): ?Recipe
    {
        return Recipe::query()
            ->with('ingredients')
            ->where('menu_item_option_id', $optionId)
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderByRaw('branch_id IS NULL')
            ->orderByRaw("status = 'locked' DESC")
            ->first();
    }

    private function locationFor(int $branchId): ?Location
    {
        return Location::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
