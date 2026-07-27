<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncMenuItemBranchOptionsRequest;
use App\Models\MenuItem;
use App\Models\MenuItemOptionBranchPrice;
use Illuminate\Http\JsonResponse;

class MenuItemBranchOptionController extends Controller
{
    /**
     * Return existing branch price overrides for all siblings of this menu item.
     */
    public function show(MenuItem $menuItem): JsonResponse
    {
        $siblings = MenuItem::query()
            ->where('slug', $menuItem->slug)
            ->with(['options' => fn ($q) => $q->orderBy('display_order'), 'options.branchPrices'])
            ->get();

        $result = [];

        foreach ($siblings as $sibling) {
            $branchId = (string) $sibling->branch_id;
            $result[$branchId] = [
                'available' => $sibling->is_available,
                'options' => [],
            ];

            foreach ($sibling->options as $option) {
                $override = $option->branchPrices->firstWhere('branch_id', $sibling->branch_id);
                $result[$branchId]['options'][$option->option_key] = $override?->price !== null
                    ? (float) $override->price
                    : null;
            }
        }

        return response()->success($result);
    }

    /**
     * Batch-update branch price overrides for the same menu item (matched by slug) on other branches.
     */
    public function update(SyncMenuItemBranchOptionsRequest $request, MenuItem $menuItem): JsonResponse
    {
        $branches = $request->validated('branches');
        $updated = [];
        $skipped = [];

        foreach ($branches as $branchId => $payload) {
            $branchId = (int) $branchId;
            $sibling = MenuItem::query()
                ->where('branch_id', $branchId)
                ->where('slug', $menuItem->slug)
                ->first();

            // No sibling row means this dish does not exist at that branch at
            // all, so there is nothing to override. Recorded rather than skipped
            // in silence: this used to `continue`, which let the editor report a
            // successful save while writing nothing — the exact symptom of a
            // branch that was never given a copy of the menu.
            if (! $sibling) {
                $skipped[] = $branchId;

                continue;
            }

            foreach ($payload['options'] as $row) {
                $option = $sibling->options()->where('option_key', $row['option_key'])->first();

                if (! $option) {
                    continue;
                }

                $data = [];

                if (array_key_exists('price', $row) && $row['price'] !== null) {
                    $data['price'] = $row['price'];
                }

                if (array_key_exists('is_available', $row) && $row['is_available'] !== null) {
                    $data['is_available'] = (bool) $row['is_available'];
                }

                if ($data !== []) {
                    MenuItemOptionBranchPrice::updateOrCreate(
                        ['menu_item_option_id' => $option->id, 'branch_id' => $branchId],
                        $data
                    );
                    $updated[] = $option->id;
                }
            }
        }

        $message = 'Branch options synced.';

        if ($skipped !== []) {
            $names = \App\Models\Branch::whereIn('id', $skipped)->pluck('name')->all();
            $message .= ' Skipped '.count($skipped).' branch(es) where this item does not exist: '
                .implode(', ', $names ?: array_map('strval', $skipped)).'.';
        }

        return response()->success([
            'updated_option_ids' => array_values(array_unique($updated)),
            'skipped_branch_ids' => $skipped,
        ], $message);
    }
}
