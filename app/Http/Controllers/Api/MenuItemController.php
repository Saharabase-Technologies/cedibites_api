<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMenuItemRequest;
use App\Http\Requests\UpdateMenuItemRequest;
use App\Http\Resources\MenuItemCollection;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use App\Models\MenuItemRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MenuItemController extends Controller
{
    protected function menuItemWith(): array
    {
        return [
            'branch',
            'category',
            'options' => fn ($q) => $q->orderBy('display_order'),
            'options.media',
            'options.branchPrices',
            'tags',
        ];
    }

    /**
     * The admin catalogue.
     *
     * Deliberately not `index()`. That one belongs to the storefront: it is
     * unauthenticated, and its branch filter runs through servedAt() — which,
     * now that every branch serves every dish, returns the same list whatever
     * you ask it for. The admin needs the opposite shape: one row per dish,
     * company-wide, carrying *where it is served* rather than being filtered
     * by it. Hence `branches`, which is what the availability matrix reads.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = MenuItem::with([...$this->menuItemWith(), 'branches'])
            ->when($request->category_id, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($request->filled('is_available'), fn ($q) => $q->where('is_available', $request->boolean('is_available')))
            ->orderBy('name');

        return response()->success(MenuItemResource::collection($query->get()));
    }

    public function index(Request $request): JsonResponse
    {
        // "What does branch X sell right now" and "what is on branch X's menu"
        // are different questions, and the till is asking the first. It passes
        // is_available=true meaning "only what you will actually sell me", so
        // that is the flag that picks the scope — a branch's own sold-out mark
        // counts alongside the company-wide one.
        //
        // Without this the manager's sold-out toggle was decorative: it wrote
        // the pivot and no selling screen ever read it.
        $onSaleOnly = $request->branch_id && $request->is_available !== null && $request->boolean('is_available');

        $query = MenuItem::with($this->menuItemWith())
            // servedAt, not branch_id: a dish is one row served at many branches
            // now. The scope still answers to branch_id for any item the merge
            // has not reached yet.
            ->when($request->branch_id, fn ($q, $branchId) => $onSaleOnly
                ? $q->onSaleAt($branchId)
                : $q->servedAt($branchId))
            ->when($request->category_id, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when(! $onSaleOnly && $request->is_available !== null,
                fn ($q) => $q->where('is_available', $request->boolean('is_available')))
            ->when($request->boolean('popular'), fn ($q) => $q->whereHas('tags', fn ($tq) => $tq->where('slug', 'popular')));

        if ($request->has('per_page')) {
            $menuItems = $query->paginate($request->per_page);

            return response()->paginated(new MenuItemCollection($menuItems));
        }

        return response()->success(MenuItemResource::collection($query->get()));
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        try {
            $data = $request->safe()->except(['tag_ids']);
            $menuItem = MenuItem::create($data);

            if ($request->filled('tag_ids')) {
                $menuItem->tags()->sync($request->input('tag_ids'));
            }

            if ($request->input('pricing_type') === 'simple') {
                $this->syncSinglePriceOption($menuItem, (float) $request->input('price', 0));
            }

            return response()->created(
                new MenuItemResource($menuItem->fresh($this->menuItemWith()))
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'menu_items_branch_id_slug_unique')) {
                return response()->json([
                    'message' => 'A menu item with this name already exists in this branch.',
                    'errors' => [
                        'slug' => ['This menu item name is already taken for this branch.'],
                    ],
                ], 422);
            }

            \Log::error('Menu item creation failed', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return response()->json([
                'message' => 'Failed to create menu item. Please check your data and try again.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error creating menu item', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(MenuItem $menuItem): JsonResponse
    {
        $menuItem->load($this->menuItemWith());

        return response()->success(new MenuItemResource($menuItem));
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        try {
            $menuItem->update($request->safe()->except(['tag_ids']));

            if ($request->has('tag_ids')) {
                $menuItem->tags()->sync($request->input('tag_ids', []));
            }

            if ($request->input('pricing_type') === 'simple') {
                $this->syncSinglePriceOption($menuItem, (float) $request->input('price', 0));
            }

            return response()->success(
                new MenuItemResource($menuItem->fresh($this->menuItemWith()))
            );
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'menu_items_branch_id_slug_unique')) {
                return response()->json([
                    'message' => 'A menu item with this name already exists in this branch.',
                    'errors' => [
                        'slug' => ['This menu item name is already taken for this branch.'],
                    ],
                ], 422);
            }

            \Log::error('Menu item update failed', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'item_id' => $menuItem->id,
            ]);

            return response()->json([
                'message' => 'Failed to update menu item. Please check your data and try again.',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error updating menu item', [
                'error' => $e->getMessage(),
                'data' => $request->validated(),
                'item_id' => $menuItem->id,
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function syncSinglePriceOption(MenuItem $menuItem, float $price): void
    {
        // Use withTrashed so restoring a soft-deleted standard option doesn't
        // violate the (menu_item_id, option_key) unique constraint.
        $option = $menuItem->options()->withTrashed()->firstOrNew(['option_key' => 'standard']);
        $option->fill([
            'option_label' => 'Standard',
            'price' => $price,
            'display_order' => 0,
            'is_available' => true,
            'deleted_at' => null,
        ]);
        $option->save();

        // Soft-delete any non-standard options left over from a previous options-mode setup.
        $menuItem->options()->where('option_key', '!=', 'standard')->delete();
    }

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
            'branch_id' => ['required', 'exists:branches,id'],
        ]);

        try {
            $import = new \App\Imports\MenuItemsImport($request->branch_id);

            Excel::import($import, $request->file('csv_file'));

            $failures = $import->failures();
            $errors = $import->errors();

            $failureDetails = [];
            foreach ($failures as $failure) {
                $failureDetails[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values(),
                ];
            }

            $errorDetails = [];
            foreach ($errors as $error) {
                $errorDetails[] = [
                    'message' => $error->getMessage(),
                    'line' => $error->getLine() ?? null,
                ];
            }

            $imported = $import->getImportedCount();
            $skipped = $import->getSkippedCount();
            $failed = count($failureDetails);

            return response()->success([
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'total_processed' => $imported + $skipped + $failed,
                'validation_failures' => $failureDetails,
                'errors' => $errorDetails,
            ], "Successfully imported {$imported} menu items.");

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $failureDetails = [];

            foreach ($failures as $failure) {
                $failureDetails[] = [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'values' => $failure->values(),
                ];
            }

            return response()->json([
                'message' => 'Validation failed for some rows.',
                'failures' => $failureDetails,
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Bulk import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import menu items.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkImportPreview(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
            'branch_id' => ['required', 'exists:branches,id'],
        ]);

        try {
            $file = $request->file('csv_file');

            $rows = Excel::toCollection(new \App\Imports\MenuItemsImport($request->branch_id), $file)->first();

            $validRows = [];
            $invalidRows = [];
            $skipped = 0;

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                if ($row->filter()->isEmpty()) {
                    $skipped++;

                    continue;
                }

                $rowErrors = [];

                if (empty(trim($row['name'] ?? ''))) {
                    $rowErrors[] = 'Name is required';
                }

                if (! empty($row['price']) && ! is_numeric($row['price'])) {
                    $rowErrors[] = 'Price must be a number';
                }

                if (! empty($row['price']) && (float) $row['price'] < 0) {
                    $rowErrors[] = 'Price cannot be negative';
                }

                $rowPreview = [
                    'row' => $rowNumber,
                    'name' => trim($row['name'] ?? ''),
                    'category' => trim($row['category'] ?? ''),
                    'description' => trim($row['description'] ?? ''),
                    'price' => ! empty($row['price']) ? (float) $row['price'] : null,
                    'is_available' => $this->parseBoolean($row['is_available'] ?? 'true'),
                    'status' => empty($rowErrors) ? 'valid' : 'invalid',
                    'errors' => $rowErrors,
                ];

                if (empty($rowErrors)) {
                    $validRows[] = $rowPreview;
                } else {
                    $invalidRows[] = $rowPreview;
                }
            }

            return response()->success([
                'total_rows' => $rows->count(),
                'valid_rows' => count($validRows),
                'invalid_rows' => count($invalidRows),
                'skipped_rows' => $skipped,
                'preview' => array_merge($validRows, $invalidRows),
                'can_import' => count($validRows) > 0,
            ], 'CSV preview generated successfully.');

        } catch (\Exception $e) {
            \Log::error('Bulk import preview failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to preview CSV file.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function parseBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['true', '1', 'yes', 'y'], true);
    }

    public function rate(Request $request, MenuItem $menuItem): JsonResponse
    {
        $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'order_item_id' => ['nullable', 'exists:order_items,id'],
        ]);

        $customerId = $request->user()->id;

        MenuItemRating::updateOrCreate(
            ['customer_id' => $customerId, 'menu_item_id' => $menuItem->id],
            ['rating' => $request->input('rating'), 'order_item_id' => $request->input('order_item_id')]
        );

        $avg = MenuItemRating::where('menu_item_id', $menuItem->id)->avg('rating');
        $count = MenuItemRating::where('menu_item_id', $menuItem->id)->count();

        $menuItem->update(['rating' => round((float) $avg, 1), 'rating_count' => $count]);

        return response()->success([
            'rating' => $menuItem->fresh()->rating,
            'rating_count' => $menuItem->fresh()->rating_count,
        ], 'Rating submitted successfully.');
    }

    public function destroy(MenuItem $menuItem): JsonResponse
    {
        try {
            $menuItem->delete();

            return response()->deleted();
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }
}
