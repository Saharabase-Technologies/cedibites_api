<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMenuItemOptionRequest;
use App\Http\Requests\UpdateMenuItemOptionRequest;
use App\Http\Resources\MenuItemOptionResource;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuItemOptionController extends Controller
{
    public function index(MenuItem $menuItem): JsonResponse
    {
        $options = $menuItem->options()->orderBy('display_order')->get();

        return response()->success(
            MenuItemOptionResource::collection($options),
            'Menu item options retrieved successfully.'
        );
    }

    public function store(StoreMenuItemOptionRequest $request, MenuItem $menuItem): JsonResponse
    {
        $option = $menuItem->options()->create($request->validated());

        return response()->success(
            new MenuItemOptionResource($option),
            'Menu item option created successfully.',
            201
        );
    }

    public function show(MenuItem $menuItem, MenuItemOption $option): JsonResponse
    {
        if ($option->menu_item_id !== $menuItem->id) {
            return response()->error('Option not found for this menu item.', 404);
        }

        return response()->success(
            new MenuItemOptionResource($option),
            'Menu item option retrieved successfully.'
        );
    }

    public function update(UpdateMenuItemOptionRequest $request, MenuItem $menuItem, MenuItemOption $option): JsonResponse
    {
        if ($option->menu_item_id !== $menuItem->id) {
            return response()->error('Option not found for this menu item.', 404);
        }

        $option->update($request->validated());

        return response()->success(
            new MenuItemOptionResource($option->fresh()),
            'Menu item option updated successfully.'
        );
    }

    public function destroy(MenuItem $menuItem, MenuItemOption $option): JsonResponse
    {
        if ($option->menu_item_id !== $menuItem->id) {
            return response()->error('Option not found for this menu item.', 404);
        }

        if ($menuItem->options()->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last option. Each menu item must have at least one option.',
            ], 422);
        }

        try {
            $option->delete();

            return response()->success(null, 'Menu item option deleted successfully.');
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    public function uploadImage(MenuItem $menuItem, MenuItemOption $option, Request $request): JsonResponse
    {
        if ($option->menu_item_id !== $menuItem->id) {
            return response()->error('Option not found for this menu item.', 404);
        }

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ]);

        try {
            $option->clearMediaCollection('menu-item-options');
            $option->addMediaFromRequest('image')->toMediaCollection('menu-item-options');

            return response()->success(
                new MenuItemOptionResource($option->fresh()),
                'Image uploaded successfully.'
            );
        } catch (\Exception $e) {
            \Log::error('Menu option image upload failed', [
                'error' => $e->getMessage(),
                'option_id' => $option->id,
            ]);

            return response()->json([
                'message' => 'Failed to upload image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Take the photo off an option.
     *
     * Upload could only ever replace, so a photo put on the wrong option was
     * there for good unless you had another one to hand. Clearing the
     * collection is enough — the item falls back to the next option's photo,
     * and to the fork-and-knife placeholder when none of them has one.
     */
    public function deleteImage(MenuItem $menuItem, MenuItemOption $option): JsonResponse
    {
        if ($option->menu_item_id !== $menuItem->id) {
            return response()->error('Option not found for this menu item.', 404);
        }

        $option->clearMediaCollection('menu-item-options');

        return response()->success(
            new MenuItemOptionResource($option->fresh()),
            'Image removed.'
        );
    }
}
