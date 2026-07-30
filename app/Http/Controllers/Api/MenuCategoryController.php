<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMenuCategoryRequest;
use App\Http\Requests\UpdateMenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MenuCategory::withCount('menuItems');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // A branch's categories are the categories of the dishes it serves.
        //
        // `menu_items` was unified — one row per dish, sold at many branches via
        // the pivot — but `menu_categories` still carries the old per-branch
        // `branch_id`, so filtering on it returned nothing for any branch that
        // was never given its own category rows. The till then showed the items
        // with a single "All" tab and no way to narrow them, because the items
        // are unified and their categories are not.
        //
        // Derived from what the branch actually serves rather than from the
        // legacy column, so this reads correctly whether or not the categories
        // are ever merged. Legacy rows still match on their own branch_id.
        if ($request->has('branch_id')) {
            $branchId = $request->integer('branch_id');

            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereHas('menuItems', fn ($items) => $items->servedAt($branchId));
            });
        }

        $categories = $query->orderBy('display_order')->get();

        return response()->success(
            MenuCategoryResource::collection($categories),
            'Menu categories retrieved successfully.'
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateMenuCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (empty($data['slug'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
        }

        $category = MenuCategory::create($data);

        return response()->success(
            new MenuCategoryResource($category),
            'Menu category created successfully.',
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(MenuCategory $menuCategory): JsonResponse
    {
        return response()->success(
            new MenuCategoryResource($menuCategory->loadCount('menuItems')),
            'Menu category retrieved successfully.'
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateMenuCategoryRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $menuCategory->update($request->validated());

        return response()->success(
            new MenuCategoryResource($menuCategory),
            'Menu category updated successfully.'
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MenuCategory $menuCategory): JsonResponse
    {
        if ($menuCategory->menuItems()->count() > 0) {
            return response()->error('Cannot delete category with menu items.', 422);
        }

        $menuCategory->delete();

        return response()->success(null, 'Menu category deleted successfully.');
    }
}
