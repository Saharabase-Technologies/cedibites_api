<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Production\ProductionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(
        private readonly ProductionService $service,
    ) {}

    /**
     * Record mother-kitchen consumption: posts a negative `production` movement
     * per line, lowering on-hand stock at the warehouse.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'occurred_at' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $movements = $this->service->record($data, $request->user());
        } catch (InventoryException $e) {
            return response()->error($e->getMessage(), 422);
        }

        return response()->success(
            ['count' => count($movements)],
            'Stock consumption recorded.',
        );
    }
}
