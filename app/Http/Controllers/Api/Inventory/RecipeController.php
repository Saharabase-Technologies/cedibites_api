<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\RecipeResource;
use App\Models\Inventory\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recipe / BOM management for the IMS. One recipe per menu-item option, with an
 * optional per-branch override (branch_id). Powers automatic stock deduction.
 */
class RecipeController extends Controller
{
    private const RELATIONS = [
        'menuItemOption.menuItem',
        'branch',
        'lockedBy',
        'ingredients.item.baseUnit',
        'ingredients.unit',
    ];

    public function index(Request $request): JsonResponse
    {
        $recipes = Recipe::query()
            ->with(self::RELATIONS)
            ->when($request->filled('menu_item_option_id'), fn ($q) => $q->where('menu_item_option_id', $request->integer('menu_item_option_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->boolean('global_only'), fn ($q) => $q->whereNull('branch_id'))
            ->latest('id')
            ->get();

        return response()->success(RecipeResource::collection($recipes));
    }

    public function show(Recipe $recipe): JsonResponse
    {
        return response()->success(new RecipeResource($recipe->load(self::RELATIONS)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $recipe = DB::transaction(function () use ($data) {
            $recipe = Recipe::create([
                'menu_item_option_id' => $data['menu_item_option_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'is_default' => empty($data['branch_id']),
                'status' => $data['status'] ?? 'draft',
                'version' => 1,
                'yield_qty' => $data['yield_qty'] ?? 1,
            ]);
            $this->syncIngredients($recipe, $data['ingredients']);

            return $recipe;
        });

        return response()->success(new RecipeResource($recipe->load(self::RELATIONS)), 'Recipe created.');
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $this->validatePayload($request, $recipe);

        DB::transaction(function () use ($recipe, $data) {
            $recipe->update([
                'menu_item_option_id' => $data['menu_item_option_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'is_default' => empty($data['branch_id']),
                'status' => $data['status'] ?? $recipe->status,
                'yield_qty' => $data['yield_qty'] ?? $recipe->yield_qty,
                'version' => $recipe->version + 1,
            ]);
            $this->syncIngredients($recipe, $data['ingredients']);
        });

        return response()->success(new RecipeResource($recipe->load(self::RELATIONS)), 'Recipe updated.');
    }

    public function destroy(Recipe $recipe): JsonResponse
    {
        $recipe->delete();

        return response()->success(null, 'Recipe deleted.');
    }

    public function lock(Recipe $recipe, Request $request): JsonResponse
    {
        $recipe->update([
            'status' => 'locked',
            'locked_by_id' => $request->user()->id,
            'locked_at' => now(),
        ]);

        return response()->success(new RecipeResource($recipe->load(self::RELATIONS)), 'Recipe locked.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatePayload(Request $request, ?Recipe $recipe = null): array
    {
        return $request->validate([
            'menu_item_option_id' => ['required', 'integer', 'exists:menu_item_options,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', 'in:draft,observation,locked'],
            'yield_qty' => ['nullable', 'numeric', 'min:0.0001'],
            'ingredients' => ['required', 'array', 'min:1'],
            'ingredients.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'ingredients.*.unit_id' => ['required', 'integer', 'exists:inventory_units,id'],
            'ingredients.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * @param  array<int,array{item_id:int, unit_id:int, quantity:float}>  $ingredients
     */
    private function syncIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();
        foreach ($ingredients as $row) {
            $recipe->ingredients()->create([
                'item_id' => $row['item_id'],
                'unit_id' => $row['unit_id'],
                'quantity' => $row['quantity'],
            ]);
        }
    }
}
