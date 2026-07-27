<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Inventory\Location;
use Illuminate\Support\Facades\Log;

/**
 * Everything a branch needs before it can trade, in one place.
 *
 * A branch is not just a row in `branches`. It needs an inventory location or
 * its manager cannot see stock, requisition anything, or close the day — and
 * worse, its sales fall through to debiting the mother kitchen (see
 * RecipeDeductionService::resolveDeductionLocation). Nothing created that
 * location: the catalog seeder makes one for the first four branches and
 * anything added afterwards silently had none.
 *
 * Menu provisioning belongs here too and is deliberately absent. Under the
 * current schema `menu_items` carries a branch_id with UNIQUE(branch_id, slug),
 * so making the menu available means duplicating every category, item, option
 * and add-on — and Phase 3 of docs/BRANCH_ISOLATION_PLAN.md then has to merge
 * those duplicates back into one global menu. Cloning here would create work
 * for that migration to undo. Once the menu is global this gains one more step:
 * insert the `menu_item_branches` rows.
 */
class BranchProvisioningService
{
    /**
     * Give a branch everything it needs. Safe to re-run — each step checks
     * before it creates, so this doubles as a repair for branches that predate
     * it.
     *
     * @return array{location: Location|null, created: bool}
     */
    public function provision(Branch $branch): array
    {
        $existing = $this->existingLocation($branch);

        if ($existing) {
            return ['location' => $existing, 'created' => false];
        }

        $location = Location::create([
            'code' => $this->nextSatelliteCode(),
            'name' => $branch->name.' Branch',
            'type' => 'satellite',
            'branch_id' => $branch->id,
            'address' => $branch->address,
            'is_active' => true,
        ]);

        Log::info('Branch provisioned with an inventory location.', [
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'location_id' => $location->id,
            'location_code' => $location->code,
        ]);

        return ['location' => $location, 'created' => true];
    }

    /**
     * The satellite location already serving this branch, if any.
     *
     * Includes inactive ones on purpose: a branch with a deactivated location
     * has a deliberate history, and quietly creating a second one alongside it
     * would split its stock in two.
     */
    public function existingLocation(Branch $branch): ?Location
    {
        return Location::query()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Next free SK-NNN, matching the codes the catalog seeder writes.
     *
     * Derived from the highest existing suffix rather than a count, so a gap
     * left by a deleted location does not hand out a code that is already
     * taken. Soft-deleted rows still hold their unique code, so they count.
     */
    private function nextSatelliteCode(): string
    {
        $highest = Location::withTrashed()
            ->where('code', 'like', 'SK-%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 3))
            ->max() ?? 0;

        return 'SK-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Active branches with no inventory location — the drift this service exists
     * to stop. Used by the backfill command and by inventory:scope-check.
     *
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    public function unprovisionedBranches(): \Illuminate\Support\Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->whereDoesntHave('inventoryLocations')
            ->orderBy('id')
            ->get();
    }
}
