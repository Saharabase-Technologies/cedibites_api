<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin's branch availability matrix: every dish against every branch.
 *
 * This replaces MenuItemBranchOptionController, which resolved "the same dish
 * at another branch" as a *sibling row* (`where branch_id + slug`). That was
 * correct under the one-dish-per-branch model. `menu:unify` collapsed those
 * siblings into one row per dish, so the lookup stopped matching anything: the
 * editor listed a single branch, wrote nothing to any other, and reported
 * success either way.
 *
 * Availability now lives where Phase 3 put it — the `menu_item_branches` pivot,
 * the same column MenuItemAvailabilityController writes when a branch manager
 * marks something sold out. One source of truth, two doors onto it: the manager
 * has one branch and today's stock, the admin has the whole grid.
 *
 * Availability is the only thing that varies by branch. Name, photo, options,
 * category, tags and price are company-wide.
 */
class MenuBranchAvailabilityController extends Controller
{
    /**
     * The whole matrix in one call — items × branches.
     *
     * Sent as one payload rather than per-item so the grid does not fire N
     * requests to paint, and so "served nowhere" is visible at a glance.
     */
    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $items = MenuItem::query()
            ->with(['category:id,name', 'branches:id'])
            ->orderBy('name')
            ->get();

        return response()->success([
            'branches' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
            ])->values(),

            'items' => $items->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                // Off everywhere beats on here, exactly as the manager endpoint
                // reads it: a dish withdrawn company-wide is not something a
                // branch row can contradict.
                'available_everywhere' => (bool) $item->is_available,
                'branches' => $item->branches
                    ->mapWithKeys(fn ($branch) => [
                        (string) $branch->id => (bool) $branch->pivot->is_available,
                    ]),
            ])->values(),
        ]);
    }

    /**
     * Change one dish at one branch.
     *
     * Two separate statements, never conflated:
     *
     *   served    — is this dish on that branch's menu at all. Attach/detach.
     *   available — has the branch got it today. The pivot flag, normally the
     *               manager's to set, which an admin may override.
     *
     * Detaching rather than flagging is deliberate for the first: `is_available
     * = false` means "we have it, we are out today", which is the manager's
     * word to overwrite each morning. An admin removing a dish from a branch is
     * saying it is not on that branch's menu at all.
     *
     * `served: true` deliberately leaves an existing row's flag alone, so
     * re-serving a dish does not silently overrule a branch that marked it off
     * this morning. Clearing that flag is the other verb, and has to be asked
     * for — which until now was impossible: this took only `served`, and
     * re-serving went through `syncWithoutDetaching([$branch->id])`. A bare id
     * array carries no pivot attributes, and sync does not update an existing
     * row without them, so "serve here" on a sold-out row wrote nothing at all.
     * Combined with `menu:unify` stamping the flag from the company-wide one,
     * whole branches read sold out with no way back.
     */
    public function update(Request $request, MenuItem $menuItem, Branch $branch): JsonResponse
    {
        $validated = $request->validate([
            'served' => ['sometimes', 'boolean'],
            'available' => ['sometimes', 'boolean'],
        ]);

        if (! array_key_exists('served', $validated) && ! array_key_exists('available', $validated)) {
            return response()->error('Say what to change: served, available, or both.', 422);
        }

        $served = $validated['served'] ?? true;
        $available = $validated['available'] ?? null;

        if (! $served) {
            $menuItem->branches()->detach($branch->id);
        } elseif ($available !== null) {
            // Both statements at once: on the menu here, and on sale.
            $menuItem->branches()->syncWithoutDetaching([
                $branch->id => ['is_available' => $available],
            ]);
        } else {
            $menuItem->branches()->syncWithoutDetaching([$branch->id]);
        }

        $verb = match (true) {
            ! $served => 'No longer served',
            $available === true => 'Back on',
            $available === false => 'Marked sold out',
            default => 'Now served',
        };

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($menuItem)
            ->event('menu_branch_service_changed')
            ->withProperties([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'served' => $served,
                'available' => $available,
            ])
            ->log("{$verb}: {$menuItem->name} at {$branch->name}");

        return response()->success([
            'menu_item_id' => $menuItem->id,
            'branch_id' => $branch->id,
            'served' => $served,
            'available' => $available,
        ], match (true) {
            ! $served => "{$menuItem->name} is no longer served at {$branch->name}.",
            $available === true => "{$menuItem->name} is back on at {$branch->name}.",
            $available === false => "{$menuItem->name} is marked sold out at {$branch->name}.",
            default => "{$menuItem->name} is now served at {$branch->name}.",
        });
    }

    /**
     * Serve / stop serving a dish at every active branch at once.
     *
     * The row-level action for the matrix. Without it, putting a new dish on a
     * ten-branch menu is ten clicks.
     */
    public function updateAll(Request $request, MenuItem $menuItem): JsonResponse
    {
        $validated = $request->validate([
            'served' => ['required', 'boolean'],
        ]);

        $branchIds = Branch::query()->where('is_active', true)->pluck('id');

        if ($validated['served']) {
            $menuItem->branches()->syncWithoutDetaching($branchIds->all());
        } else {
            $menuItem->branches()->detach($branchIds->all());
        }

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($menuItem)
            ->event('menu_branch_service_changed')
            ->withProperties([
                'branch_ids' => $branchIds->all(),
                'served' => $validated['served'],
                'all_branches' => true,
            ])
            ->log(($validated['served'] ? 'Now served everywhere' : 'Withdrawn from every branch')
                .": {$menuItem->name}");

        return response()->success([
            'menu_item_id' => $menuItem->id,
            'branch_ids' => $branchIds->all(),
            'served' => $validated['served'],
        ], $validated['served']
            ? "{$menuItem->name} is now served at every branch."
            : "{$menuItem->name} has been withdrawn from every branch.");
    }
}
