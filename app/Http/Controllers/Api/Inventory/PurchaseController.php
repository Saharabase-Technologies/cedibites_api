<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Purchases\PurchaseService;
use App\Enums\Permission;
use App\Events\Inventory\PurchaseOrderBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\RecordPurchaseRequest;
use App\Http\Resources\Inventory\PurchaseResource;
use App\Models\Inventory\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    private const RELATIONS = [
        'purchaseOrder',
        'supplier',
        'destinationLocation',
        'recordedBy',
        'items.item.baseUnit',
        'items.unit',
    ];

    public function __construct(
        private readonly PurchaseService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::query()
            ->with(self::RELATIONS)
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->has('is_urgent_buy'), fn ($q) => $q->where('is_urgent_buy', $request->boolean('is_urgent_buy')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w
                    ->where('reference', 'like', $term)
                    ->orWhere('invoice_number', 'like', $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $term)));
            })
            ->latest('received_at')
            ->get();

        return response()->success(PurchaseResource::collection($purchases));
    }

    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load(self::RELATIONS);

        return response()->success(new PurchaseResource($purchase));
    }

    public function store(RecordPurchaseRequest $request): JsonResponse
    {
        // Separation of duties: an ad-hoc (no-PO) urgent buy needs the urgent-buy grant.
        if ($request->boolean('is_urgent_buy') && $request->user()->cannot(Permission::InventoryPurchaseUrgentBuy->value)) {
            return response()->forbidden('You are not permitted to record urgent buys.');
        }

        try {
            $purchase = $this->service->receive($request->validated(), $request->user());
        } catch (InventoryException $e) {
            return response()->error($e->getMessage(), 422);
        }

        // A receipt advances the linked PO's status — signal listeners to refetch.
        if ($purchase->purchase_order_id) {
            $po = $purchase->purchaseOrder()->first();
            if ($po) {
                PurchaseOrderBroadcastEvent::dispatch($po->id, $po->reference, $po->status->value, 'received');
            }
        }

        return response()->success(
            new PurchaseResource($purchase->fresh(self::RELATIONS)),
            'Purchase recorded.',
        );
    }
}
