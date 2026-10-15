<?php

namespace App\Http\Controllers\Api;

use App\Events\OrderBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderRound;
use App\Services\OrderManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'owing',
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

        // The kitchen slip of a Pay later order: FOR THE KITCHEN, NOT PAID (D5).
        // It is not the customer's receipt, so it is logged as its own kind and
        // leaves the receipt count alone; the paid receipt that follows is
        // still the original (D6).
        if ($request->input('slip') === 'kitchen') {
            if (! $order->paid_later) {
                return response()->error('Only a Pay later order has a kitchen slip.', 422);
            }

            // A round's own slip carries only its items (Phase 4b).
            $round = $request->filled('round')
                ? $order->rounds()->where('number', $request->integer('round'))->first()
                : null;

            if ($request->filled('round') && ! $round) {
                return response()->error('That round is not on this order.', 422);
            }

            if ($round) {
                $round->forceFill(['kitchen_slip_printed_at' => $round->kitchen_slip_printed_at ?? now()])->save();
            } else {
                $order->forceFill(['kitchen_slip_printed_at' => $order->kitchen_slip_printed_at ?? now()])->save();
            }
            $order->receiptPrints()->create([
                'employee_id' => $employee?->id,
                'user_id' => $user->id,
                'kind' => 'kitchen',
                'reprint_number' => null,
                'copies' => 1,
                'source' => $request->input('source'),
                'printed_at' => now(),
            ]);

            return response()->success(
                new OrderResource($order->fresh()->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'items.menuItemOption.media', 'payments'])),
                'Kitchen slip recorded.'
            );
        }

        // The bill for a table that pays at the end (Phase 4b): the whole
        // order, what is paid and what is left. Not a receipt, so the count
        // is left alone.
        if ($request->input('slip') === 'bill') {
            $order->load('payments');

            if ($order->balance_due <= 0) {
                return response()->error('Nothing is owed on this order, so it has no bill.', 422);
            }

            $order->receiptPrints()->create([
                'employee_id' => $employee?->id,
                'user_id' => $user->id,
                'kind' => 'bill',
                'reprint_number' => null,
                'copies' => 1,
                'source' => $request->input('source'),
                'printed_at' => now(),
            ]);

            return response()->success(
                new OrderResource($order->fresh()->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'items.menuItemOption.media', 'payments'])),
                'Bill recorded.'
            );
        }

        // The server's clock, never the caller's. A till at Ashaiman printed a
        // reprint stamped an hour before the order it belonged to, because the
        // only timestamp on that slip not coming from here was the one the
        // machine supplied. Nothing about a print time is accepted from the
        // request.
        $printedAt = now();

        // Decided here rather than trusted from the client for the same reason
        // the timestamp is. The count is the server's, so what the slip is is
        // the server's too. Items added since the last receipt make the next
        // one an Updated Receipt (Phase 4b, D31), two slips like an original.
        $isOriginal = $order->receipt_print_count === 0;
        $isUpdated = ! $isOriginal && $order->rounds()->whereNull('receipt_printed_at')->exists();
        $kind = $isOriginal ? 'original' : ($isUpdated ? 'updated' : 'reprint');

        $order->forceFill([
            'receipt_printed_at' => $order->receipt_printed_at ?? $printedAt,
            'receipt_print_count' => $order->receipt_print_count + 1,
        ])->save();

        // Every round is on this slip, so none is waiting for a receipt now.
        $order->rounds()->whereNull('receipt_printed_at')->update(['receipt_printed_at' => $printedAt]);

        // One row per slip. `receipt_printed_at` holds only the first print and
        // the count is a bare total, so before this there was no way to say
        // when a reprint happened or who produced it — which is exactly what
        // gets asked when a customer brings a receipt back.
        $order->receiptPrints()->create([
            'employee_id' => $employee?->id,
            'user_id' => $user->id,
            'kind' => $kind,
            // The original is print 1, so the first reprint is number 1, the
            // next 2. Matches what the slip itself says.
            'reprint_number' => $kind === 'reprint' ? $order->receipt_print_count - 1 : null,
            'copies' => $kind === 'reprint' ? 1 : 2,
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

    /**
     * PATCH /employee/orders/{order}/rounds/{round}/status: move a round of
     * added items through the kitchen (Phase 4b).
     *
     * A round has its own ticket and its own status. The order's status is
     * untouched, and the customer gets no text: they are at the table.
     * Asking for the status it already has is not an error, so a second tap
     * from another screen does nothing.
     */
    public function updateRoundStatus(Request $request, Order $order, OrderRound $round): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:accepted,preparing,ready,completed'],
        ]);

        $user = $request->user();
        $employee = $user->employee;

        if (! $user->isCompanyWide() && (! $employee || ! $employee->branches()->where('branches.id', $order->branch_id)->exists())) {
            return response()->error('You can only update orders from your branch.', 403);
        }

        $updated = DB::transaction(function () use ($round, $validated) {
            $locked = OrderRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $validated['status']) {
                return true;
            }

            if (! $locked->canTransitionTo($validated['status'])) {
                return false;
            }

            $locked->update(['status' => $validated['status']]);

            return true;
        });

        if (! $updated) {
            return response()->error("This round cannot move from {$round->fresh()->status} to {$validated['status']}.", 422);
        }

        $order->refresh();

        // The order's own status has not changed, so the observer stays quiet.
        // The boards are told here.
        try {
            OrderBroadcastEvent::dispatch($order, 'updated');
        } catch (\Throwable $e) {
            Log::warning('Order broadcast failed for a round', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        return response()->success(
            new OrderResource($order->load(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'items.menuItemOption.media', 'payments', 'rounds.addedBy.user'])),
            'Round status updated.'
        );
    }
}
