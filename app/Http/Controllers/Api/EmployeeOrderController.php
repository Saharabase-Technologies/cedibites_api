<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeOrderController extends Controller
{
    public function __construct(
        protected OrderManagementService $orderManagementService
    ) {}

    /**
     * Get orders for employee's branch (or filtered for admin/tech_admin).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'branch_id', 'branch_name', 'staff_id', 'status', 'order_type', 'order_source',
            'contact_phone', 'date_from', 'date_to', 'search', 'payment_status', 'payment_method',
        ]);

        $orders = $this->orderManagementService
            ->getBranchOrders($request->user(), $filters)
            ->paginate($request->per_page ?? 15);

        return response()->success(
            OrderResource::collection($orders)->response()->getData(true),
            'Orders retrieved successfully.'
        );
    }

    /**
     * Get order statistics for employee's branch.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->orderManagementService->getBranchStats($request->user());

        return response()->success($stats, 'Statistics retrieved successfully.');
    }

    /**
     * Lightweight period summary for the current orders filter scope
     * (valid / cancelled / failed / refunded / no-charge counts + amounts).
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->only([
            'branch_id', 'branch_name', 'staff_id', 'status', 'order_type', 'order_source',
            'contact_phone', 'date_from', 'date_to', 'search', 'payment_status', 'payment_method',
        ]);

        $summary = $this->orderManagementService->getBranchPeriodSummary($request->user(), $filters);

        return response()->success($summary, 'Order summary retrieved successfully.');
    }

    /**
     * Get pending orders for quick view.
     */
    public function pending(Request $request): JsonResponse
    {
        $orders = $this->orderManagementService
            ->getPendingOrders($request->user())
            ->paginate($request->per_page ?? 10);

        return response()->success(
            OrderResource::collection($orders)->response()->getData(true),
            'Pending orders retrieved successfully.'
        );
    }

    /**
     * Update order status.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        // Check if order belongs to employee's branch. A company-wide role —
        // head office, or the call centre taking orders for every branch — holds
        // no branch assignment, so this check would refuse them every order.
        // See User::isCompanyWide.
        $employee = $request->user()->employee;
        $user = $request->user();

        if (! $user->isCompanyWide() && (! $employee || ! $employee->branches()->where('branches.id', $order->branch_id)->exists())) {
            return response()->error('You can only update orders from your branch.', 403);
        }

        $updatedOrder = $this->orderManagementService->updateOrderStatus(
            $order,
            $request->status,
            $request->notes,
            $request->user()
        );

        return response()->success(
            new OrderResource($updatedOrder->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItemOption.media', 'payments'])),
            'Order status updated successfully.'
        );
    }
}
