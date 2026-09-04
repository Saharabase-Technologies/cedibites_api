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
     * Record that a receipt was produced for this order.
     *
     * Deliberately not a status change. Printing tells you nothing about where
     * the order is in the kitchen — it is bookkeeping about the paper, and the
     * only thing reading it is the till, which needs to know whether to offer
     * "Print receipt" or "Reprint receipt".
     *
     * Idempotent in the sense that matters: the first print sets the timestamp
     * and every print bumps the count, so a run of reprints on one order stays
     * visible without the original time moving.
     */
    public function receiptPrinted(Request $request, Order $order): JsonResponse
    {
        $employee = $request->user()->employee;
        $user = $request->user();

        if (! $user->isCompanyWide() && (! $employee || ! $employee->branches()->where('branches.id', $order->branch_id)->exists())) {
            return response()->error('You can only print receipts for orders at your branch.', 403);
        }

        // The server's clock, never the caller's. A till at Ashaiman printed a
        // reprint stamped an hour before the order it belonged to, because the
        // only timestamp on that slip not coming from here was the one the
        // machine supplied. Nothing about a print time is accepted from the
        // request.
        $printedAt = now();

        // Decided here rather than trusted from the client for the same reason
        // the timestamp is. The count is the server's, so what the slip is is
        // the server's too.
        $isOriginal = $order->receipt_print_count === 0;

        $order->forceFill([
            'receipt_printed_at' => $order->receipt_printed_at ?? $printedAt,
            'receipt_print_count' => $order->receipt_print_count + 1,
        ])->save();

        // One row per slip. `receipt_printed_at` holds only the first print and
        // the count is a bare total, so before this there was no way to say
        // when a reprint happened or who produced it — which is exactly what
        // gets asked when a customer brings a receipt back.
        $order->receiptPrints()->create([
            'employee_id' => $employee?->id,
            'user_id' => $user->id,
            'kind' => $isOriginal ? 'original' : 'reprint',
            // The original is print 1, so the first reprint is number 1, the
            // next 2. Matches what the slip itself says.
            'reprint_number' => $isOriginal ? null : $order->receipt_print_count - 1,
            'copies' => $isOriginal ? 2 : 1,
            'source' => $request->input('source'),
            'printed_at' => $printedAt,
        ]);

        return response()->success(
            new OrderResource($order->fresh()->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'items.menuItemOption.media', 'payments'])),
            'Receipt print recorded.'
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
            new OrderResource($updatedOrder->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'items.menuItemOption.media', 'payments'])),
            'Order status updated successfully.'
        );
    }
}
