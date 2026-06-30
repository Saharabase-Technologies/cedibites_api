<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Production\ProductionRunService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\ProductionLogResource;
use App\Models\Inventory\ProductionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mother-kitchen production runs: consume raw inputs → yield a prepared item.
 */
class ProductionRunController extends Controller
{
    private const RELATIONS = [
        'location',
        'outputItem.baseUnit',
        'inputs.item',
        'inputs.unit',
        'producedBy',
    ];

    public function __construct(
        private readonly ProductionRunService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $logs = ProductionLog::query()
            ->with(self::RELATIONS)
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('output_item_id'), fn ($q) => $q->where('output_item_id', $request->integer('output_item_id')))
            ->latest('produced_at')
            ->get();

        return response()->success(ProductionLogResource::collection($logs));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'output_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'output_unit_id' => ['nullable', 'integer', 'exists:inventory_units,id'],
            'output_qty' => ['required', 'numeric', 'gt:0'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
            'inputs' => ['required', 'array', 'min:1'],
            'inputs.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'inputs.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $log = $this->service->record($data, $request->user());
        } catch (InventoryException $e) {
            return response()->error($e->getMessage(), 422);
        }

        return response()->success(
            new ProductionLogResource($log->load(self::RELATIONS)),
            'Production recorded.',
        );
    }
}
