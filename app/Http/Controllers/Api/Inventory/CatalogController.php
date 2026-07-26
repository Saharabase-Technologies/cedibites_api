<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Api\Inventory\Concerns\SearchesText;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\CategoryResource;
use App\Http\Resources\Inventory\ItemResource;
use App\Http\Resources\Inventory\LocationResource;
use App\Http\Resources\Inventory\SupplierResource;
use App\Http\Resources\Inventory\UnitResource;
use App\Models\Inventory\Category;
use App\Models\Inventory\Item;
use App\Models\Inventory\Location;
use App\Models\Inventory\PurchaseItem;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Read-only catalog endpoints that back the IMS portal dropdowns and list
 * screens (items, suppliers, units, categories, locations). Catalog mutations
 * are a separate, later concern.
 */
class CatalogController extends Controller
{
    use SearchesText;

    /**
     * Which locations an item's `stock_on_hand` should be summed over.
     *
     * null means "every location" - the caller oversees them all and asked for
     * no particular one.
     *
     * @return array<int,int>|null
     */
    private function itemStockScope(Request $request): ?array
    {
        $accessible = $request->user()?->accessibleLocationIds();

        if ($request->filled('location_id')) {
            $asked = $request->integer('location_id');

            // Asking about a location outside your scope yields zero rather than
            // an error - the figure is simply not yours to see.
            return $accessible === null || in_array($asked, array_map('intval', $accessible), true)
                ? [$asked]
                : [];
        }

        return $accessible;
    }

    public function items(Request $request): JsonResponse
    {
        // Whose stock is this figure? `stock_on_hand` summed every location, so a
        // branch manager saw the mother kitchen's holdings added to their own and
        // read it as theirs. Narrow it to the locations the viewer actually runs,
        // or to one they explicitly asked for. Users who oversee every location
        // (warehouse manager, admin) still get the company-wide total.
        $scopeLocations = $this->itemStockScope($request);

        $items = Item::query()
            ->with(['category', 'baseUnit', 'defaultSupplier'])
            ->withSum(
                ['stockBalances as stock_on_hand' => fn ($q) => $scopeLocations === null
                    ? $q
                    : $q->whereIn('location_id', $scopeLocations)],
                'quantity',
            )
            // The catalog stays complete by default - a branch has to be able to
            // request an item it does not hold yet. `in_stock_only` is for the
            // items screen, which is asking "what do I have?", not "what exists?".
            ->when($request->boolean('in_stock_only'), fn ($q) => $q->whereHas(
                'stockBalances',
                fn ($b) => $b
                    ->when($scopeLocations !== null, fn ($s) => $s->whereIn('location_id', $scopeLocations))
                    ->where('quantity', '>', 0),
            ))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('storage_type'), fn ($q) => $q->where('storage_type', $request->string('storage_type')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', $this->likeOperator(), $term)->orWhere('sku', $this->likeOperator(), $term));
            })
            ->orderBy('name')
            ->get();

        return response()->success(ItemResource::collection($items));
    }

    public function item(Request $request, Item $item): JsonResponse
    {
        // Same scope as the list. Without this the list showed a branch manager
        // their own 10 kg and the detail behind it showed the warehouse's - the
        // two disagreed about the same item on the same screen.
        $scope = $this->itemStockScope($request);

        $item->load(['category', 'baseUnit', 'defaultSupplier'])
            ->loadSum(
                ['stockBalances as stock_on_hand' => fn ($q) => $scope === null ? $q : $q->whereIn('location_id', $scope)],
                'quantity',
            );

        return response()->success(new ItemResource($item));
    }

    /**
     * Supply/movement history for a single item: the append-only ledger with a
     * running balance, plus each row resolved back to its source receipt + PO,
     * and the distinct suppliers that have supplied it. Powers the item detail
     * drill-down (e.g. "bought 70 L, then +20 L when it dropped to 30 L").
     */
    public function itemMovements(Request $request, Item $item): JsonResponse
    {
        // Scope the whole page, not just the headline figure: a branch manager
        // reading a ledger of warehouse movements with a running balance that is
        // not theirs is worse than showing nothing.
        $scope = $this->itemStockScope($request);

        $item->load(['category', 'baseUnit', 'defaultSupplier'])
            ->loadSum(
                ['stockBalances as stock_on_hand' => fn ($q) => $scope === null ? $q : $q->whereIn('location_id', $scope)],
                'quantity',
            );

        $movements = StockMovement::query()
            ->where('item_id', $item->id)
            ->when($scope !== null, fn ($q) => $q->whereIn('location_id', $scope))
            ->with(['location:id,name', 'user:id,name'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        // Resolve purchase-receipt references → receipt + linked PO + supplier.
        $purchaseItemIds = $movements
            ->where('reference_type', 'inventory_purchase_item')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->all();

        $purchaseItems = PurchaseItem::query()
            ->whereIn('id', $purchaseItemIds)
            ->with(['purchase.supplier:id,name,code', 'purchase.purchaseOrder:id,reference'])
            ->get()
            ->keyBy('id');

        // Resolve order references (sale deductions) → order number.
        $orderIds = $movements
            ->where('reference_type', 'order')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->all();

        $orders = \App\Models\Order::query()
            ->whereIn('id', $orderIds)
            ->get(['id', 'order_number'])
            ->keyBy('id');

        $running = 0.0;
        $suppliers = [];

        $rows = $movements->map(function (StockMovement $m) use (&$running, &$suppliers, $purchaseItems, $orders) {
            $qty = (float) $m->quantity;
            $running = round($running + $qty, 4);

            $reference = null;
            if ($m->reference_type === 'inventory_purchase_item' && $purchaseItems->has($m->reference_id)) {
                $purchase = $purchaseItems[$m->reference_id]->purchase;
                if ($purchase) {
                    $reference = [
                        'type' => 'purchase',
                        'purchase_id' => $purchase->id,
                        'purchase_reference' => $purchase->reference,
                        'purchase_order' => $purchase->purchaseOrder ? [
                            'id' => $purchase->purchaseOrder->id,
                            'reference' => $purchase->purchaseOrder->reference,
                        ] : null,
                    ];
                    if ($purchase->supplier) {
                        $suppliers[$purchase->supplier->id] = [
                            'id' => $purchase->supplier->id,
                            'name' => $purchase->supplier->name,
                            'code' => $purchase->supplier->code,
                        ];
                    }
                }
            } elseif ($m->reference_type === 'order' && $orders->has($m->reference_id)) {
                $reference = [
                    'type' => 'order',
                    'order_id' => (int) $m->reference_id,
                    'order_number' => $orders[$m->reference_id]->order_number,
                ];
            }

            return [
                'id' => $m->id,
                'occurred_at' => optional($m->occurred_at)->toIso8601String(),
                'movement_type' => $m->movement_type,
                'quantity' => $qty,
                'balance_after' => $running,
                'unit_cost_at_time' => $m->unit_cost_at_time !== null ? (float) $m->unit_cost_at_time : null,
                'location' => $m->location ? ['id' => $m->location->id, 'name' => $m->location->name] : null,
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name] : null,
                'reference' => $reference,
            ];
        });

        // Open FEFO batches (expiry-tracked items), soonest expiry first.
        $batches = \App\Models\Inventory\Batch::query()
            ->where('item_id', $item->id)
            ->when($scope !== null, fn ($q) => $q->whereIn('location_id', $scope))
            ->where('remaining_qty', '>', 0)
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'expiry_date' => optional($b->expiry_date)->toDateString(),
                'remaining_qty' => (float) $b->remaining_qty,
                'received_qty' => (float) $b->received_qty,
                'unit_cost' => (float) $b->unit_cost,
                'received_at' => optional($b->received_at)->toIso8601String(),
            ]);

        return response()->success([
            'item' => new ItemResource($item),
            'suppliers' => array_values($suppliers),
            'batches' => $batches,
            // Newest first for display; running balance was computed oldest-first.
            'movements' => $rows->reverse()->values(),
        ]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return response()->success(SupplierResource::collection($suppliers));
    }

    public function supplier(Supplier $supplier): JsonResponse
    {
        return response()->success(new SupplierResource($supplier));
    }

    public function units(Request $request): JsonResponse
    {
        $units = Unit::query()
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return response()->success(UnitResource::collection($units));
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->with('parent')
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->get();

        return response()->success(CategoryResource::collection($categories));
    }

    public function locations(Request $request): JsonResponse
    {
        // Confine the list to what the caller can actually act on. Warehouses stay
        // visible to everyone: a branch has to be able to name the warehouse it is
        // requesting stock from, and it is a counterparty on every transfer in.
        // Without this, branch pickers offered locations whose records the user
        // would then 404 on.
        $accessible = $request->user()?->accessibleLocationIds();

        $locations = Location::query()
            ->with('branch')
            ->when($accessible !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereIn('id', $accessible)->orWhere('type', 'warehouse')
            ))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return response()->success(LocationResource::collection($locations));
    }

    public function location(Location $location): JsonResponse
    {
        $location->load('branch');

        return response()->success(new LocationResource($location));
    }

    // ── Writes (gated by manage_inventory_catalog) ───────────────────────────

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $supplier = Supplier::create([
            ...$data,
            'code' => $this->nextCode(Supplier::class, 'SUP'),
            'is_active' => true,
        ]);

        return response()->success(new SupplierResource($supplier), 'Supplier created.');
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $supplier->update($data);

        return response()->success(new SupplierResource($supplier), 'Supplier updated.');
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = Category::create([
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(Category::class, $data['name']),
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);

        return response()->success(new CategoryResource($category->load('parent')), 'Category created.');
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', Rule::unique('inventory_units', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:16'],
            'dimension' => ['required', Rule::in(['mass', 'volume', 'count', 'length'])],
            'is_base_unit' => ['sometimes', 'boolean'],
        ]);

        $unit = Unit::create([...$data, 'is_active' => true]);

        return response()->success(new UnitResource($unit), 'Unit created.');
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'base_unit_id' => ['required', 'integer', 'exists:inventory_units,id'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:inventory_suppliers,id'],
            'storage_type' => ['required', Rule::in(['dry', 'cold', 'frozen', 'ambient'])],
            'is_consumable' => ['sometimes', 'boolean'],
            'expiry_tracked' => ['sometimes', 'boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'min_threshold' => ['nullable', 'numeric', 'min:0'],
            // Option A - buy-in-packs-of. Label + size travel as a pair.
            'purchase_pack_label' => ['nullable', 'string', 'max:32', 'required_with:purchase_pack_size'],
            'purchase_pack_size' => ['nullable', 'numeric', 'gt:0', 'required_with:purchase_pack_label'],
        ]);

        $item = Item::create([...$data, 'sku' => $this->nextCode(Item::class, 'ITM', 6, 'sku'), 'is_active' => true]);

        return response()->success(new ItemResource($item->load(['category', 'baseUnit', 'defaultSupplier'])), 'Item created.');
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:inventory_categories,id'],
            'base_unit_id' => ['sometimes', 'required', 'integer', 'exists:inventory_units,id'],
            'default_supplier_id' => ['nullable', 'integer', 'exists:inventory_suppliers,id'],
            'storage_type' => ['sometimes', 'required', Rule::in(['dry', 'cold', 'frozen', 'ambient'])],
            'is_consumable' => ['sometimes', 'boolean'],
            'expiry_tracked' => ['sometimes', 'boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'min_threshold' => ['nullable', 'numeric', 'min:0'],
            // Option A - buy-in-packs-of. Label + size travel as a pair.
            'purchase_pack_label' => ['nullable', 'string', 'max:32', 'required_with:purchase_pack_size'],
            'purchase_pack_size' => ['nullable', 'numeric', 'gt:0', 'required_with:purchase_pack_label'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $item->update($data);

        return response()->success(new ItemResource($item->load(['category', 'baseUnit', 'defaultSupplier'])), 'Item updated.');
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['warehouse', 'satellite'])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $prefix = $data['type'] === 'warehouse' ? 'WH' : 'SK';
        $location = Location::create([...$data, 'code' => $this->nextCode(Location::class, $prefix), 'is_active' => true]);

        return response()->success(new LocationResource($location->load('branch')), 'Location created.');
    }

    /**
     * Next sequential, unique code/sku like SUP-001 / ITM-000001.
     */
    private function nextCode(string $modelClass, string $prefix, int $pad = 3, string $column = 'code'): string
    {
        $n = (int) ($modelClass::max('id') ?? 0) + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
            $n++;
        } while ($modelClass::where($column, $code)->exists());

        return $code;
    }

    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;
        while ($modelClass::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
