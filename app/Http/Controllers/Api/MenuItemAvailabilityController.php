<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "We've run out of Jollof today."
 *
 * The branch manager's only menu power. He can take a dish off his own branch
 * and put it back; he cannot rename it, reprice it, create it or delete it.
 * Every branch serves the same menu, so all of that is company-level and
 * belongs to the Admin — including per-branch prices.
 *
 * Gated on menu.availability.manage and confined to branches the caller is
 * assigned to. Admins may set it anywhere.
 */
class MenuItemAvailabilityController extends Controller
{
    /**
     * What this branch serves, and what is currently off.
     */
    public function index(Request $request, Branch $branch): JsonResponse
    {
        if (! $this->mayActFor($request, $branch)) {
            return response()->error('Branch not found.', 404);
        }

        $items = MenuItem::query()
            ->with(['category:id,name', 'branches' => fn ($q) => $q->where('branches.id', $branch->id)])
            ->servedAt($branch->id)
            ->orderBy('name')
            ->get()
            ->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category?->name,
                // Two flags, reported separately, because they are two
                // statements and the screen has to tell them apart:
                //
                //   everywhere — on sale company-wide. The admin's.
                //   here       — we have it today. The branch's own word.
                //
                // These used to be ANDed into `available_here`, which made a
                // dish withdrawn by the admin indistinguishable from one the
                // branch had run out of — so the branch saw "sold out" against
                // a toggle that could not fix it. Off everywhere still beats on
                // here; that is now a rule the UI applies, visibly.
                'available_everywhere' => (bool) $item->is_available,
                'available_here' => (bool) ($item->branches->first()?->pivot?->is_available ?? true),
            ]);

        return response()->success($items);
    }

    /**
     * Mark a dish available or sold out at this branch.
     */
    public function update(Request $request, Branch $branch, MenuItem $menuItem): JsonResponse
    {
        if (! $this->mayActFor($request, $branch)) {
            return response()->error('Branch not found.', 404);
        }

        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $served = MenuItem::query()->servedAt($branch->id)->whereKey($menuItem->id)->exists();

        if (! $served) {
            return response()->error('This branch does not serve that item.', 404);
        }

        // syncWithoutDetaching so a dish still on the legacy branch_id gains its
        // pivot row here rather than failing to record the change.
        $menuItem->branches()->syncWithoutDetaching([
            $branch->id => ['is_available' => $validated['is_available']],
        ]);

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($menuItem)
            ->event('menu_availability_changed')
            ->withProperties([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'is_available' => $validated['is_available'],
            ])
            ->log(($validated['is_available'] ? 'Back on' : 'Sold out').": {$menuItem->name} at {$branch->name}");

        return response()->success([
            'id' => $menuItem->id,
            'name' => $menuItem->name,
            'available_here' => $validated['is_available'],
        ], $validated['is_available']
            ? "{$menuItem->name} is back on at {$branch->name}."
            : "{$menuItem->name} is marked sold out at {$branch->name}.");
    }

    /**
     * Admins act anywhere; everyone else only at branches they are assigned to.
     */
    private function mayActFor(Request $request, Branch $branch): bool
    {
        $user = $request->user();

        if ($user?->hasAnyRole(['admin', 'tech_admin'])) {
            return true;
        }

        return $user?->employee
            ?->branches()
            ->where('branches.id', $branch->id)
            ->exists() ?? false;
    }
}
