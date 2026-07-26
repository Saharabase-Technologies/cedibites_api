<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\PurchaseOrders\PurchaseOrderService;
use App\Enums\Inventory\PurchaseOrderStatus;
use App\Enums\Permission;
use App\Events\Inventory\PurchaseOrderBroadcastEvent;
use App\Http\Controllers\Api\Inventory\Concerns\SearchesText;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ApprovePurchaseOrderRequest;
use App\Http\Requests\Inventory\CancelPurchaseOrderRequest;
use App\Http\Requests\Inventory\StorePurchaseOrderRequest;
use App\Http\Requests\Inventory\UpdatePurchaseOrderRequest;
use App\Http\Resources\Inventory\PurchaseOrderResource;
use App\Models\Inventory\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use SearchesText;

    private const RELATIONS = [
        'supplier',
        'destinationLocation',
        'createdBy',
        'approvedBy',
        'cancelledBy',
        'items.item.baseUnit',
        'items.unit',
    ];

    public function __construct(
        private readonly PurchaseOrderService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = PurchaseOrder::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('destination_location_id'), fn ($q) => $q->where('destination_location_id', $request->integer('destination_location_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w
                    ->where('reference', $this->likeOperator(), $term)
                    ->orWhereHas('supplier', fn ($s) => $s->where('name', $this->likeOperator(), $term)));
            })
            ->latest()
            ->get();

        return response()->success(PurchaseOrderResource::collection($orders));
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($purchaseOrder->isVisibleTo($request->user()), 404);

        $purchaseOrder->load(self::RELATIONS);

        return response()->success(new PurchaseOrderResource($purchaseOrder));
    }

    /**
     * Authenticity check by the unguessable verification code (QR signature).
     * Includes soft-deleted POs so historical records always resolve.
     */
    public function verify(string $code): JsonResponse
    {
        $po = PurchaseOrder::withTrashed()
            ->with(self::RELATIONS)
            ->where('verification_code', $code)
            ->first();

        if (! $po) {
            return response()->error('No purchase order matches this verification code.', 404);
        }

        return response()->success(new PurchaseOrderResource($po));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->service->create($request->validated(), $request->user());
        $this->broadcast($po, 'created');

        return response()->success($this->fresh($po), 'Purchase order created.');
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        // Editing a PO that's awaiting approval is an approver-only action that
        // saves the edit AND approves it in one step (→ sent).
        if ($purchaseOrder->status === PurchaseOrderStatus::PendingApproval) {
            if ($request->user()->cannot(Permission::InventoryPurchaseOrderApprove->value)) {
                return response()->forbidden('Editing an order awaiting approval requires approval permission.');
            }

            return $this->guard(function () use ($request, $purchaseOrder) {
                $po = $this->service->editAndApprove($purchaseOrder, $request->validated(), $request->user());
                $this->broadcast($po, 'edited_approved');

                return response()->success($this->fresh($po), 'Purchase order updated and approved.');
            });
        }

        return $this->guard(function () use ($request, $purchaseOrder) {
            $po = $this->service->update($purchaseOrder, $request->validated());
            $this->broadcast($po, 'updated');

            return response()->success($this->fresh($po), 'Purchase order updated.');
        });
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->guard(function () use ($purchaseOrder) {
            $po = $this->service->submit($purchaseOrder);
            $this->broadcast($po, 'submitted');

            return response()->success($this->fresh($po), 'Purchase order submitted.');
        });
    }

    public function approve(ApprovePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->guard(function () use ($request, $purchaseOrder) {
            $po = $this->service->approve($purchaseOrder, $request->user());
            $this->broadcast($po, 'approved');

            return response()->success($this->fresh($po), 'Purchase order approved.');
        });
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->guard(function () use ($request, $purchaseOrder) {
            $po = $this->service->cancel($purchaseOrder, $request->user(), $request->validated()['reason']);
            $this->broadcast($po, 'cancelled');

            return response()->success($this->fresh($po), 'Purchase order cancelled.');
        });
    }

    public function close(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->guard(function () use ($purchaseOrder) {
            $po = $this->service->close($purchaseOrder);
            $this->broadcast($po, 'closed');

            return response()->success($this->fresh($po), 'Purchase order closed.');
        });
    }

    private function fresh(PurchaseOrder $po): PurchaseOrderResource
    {
        return new PurchaseOrderResource($po->fresh(self::RELATIONS));
    }

    private function broadcast(PurchaseOrder $po, string $changeType): void
    {
        PurchaseOrderBroadcastEvent::dispatch($po->id, $po->reference, $po->status->value, $changeType);
    }

    /**
     * Map domain guard failures to 422.
     */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (InventoryException $e) {
            return response()->error($e->getMessage(), 422);
        }
    }
}
