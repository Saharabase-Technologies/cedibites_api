<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderRound;
use App\Services\Orders\RoundCancellation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A round cancelled on its own (D40, D45), beside the order's own cancel
 * requests (CancelRequestController): staff ask, an admin approves or
 * rejects, an admin may cancel outright.
 */
class RoundCancelController extends Controller
{
    public function __construct(private RoundCancellation $cancellation) {}

    /** POST /employee/orders/{order}/rounds/{round}/request-cancel */
    public function requestCancel(Request $request, Order $order, OrderRound $round): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // The same scope as an order's cancel request: the caller's own
        // branches, or every branch for a company-wide role.
        $user = $request->user();
        $employee = $user?->employee;

        if (! $user?->isCompanyWide() && (! $employee || ! $employee->branches()->where('branches.id', $order->branch_id)->exists())) {
            return response()->json(['message' => 'You can only request cancellation for orders from your branch.'], 403);
        }

        $refusal = $this->cancellation->request($order, $round, $user, trim($validated['reason']));

        return $this->answer($order, $refusal, 'Cancellation request submitted.');
    }

    /** POST /admin/orders/{order}/rounds/{round}/approve-cancel */
    public function approve(Request $request, Order $order, OrderRound $round): JsonResponse
    {
        if (! $round->cancelAsked()) {
            return response()->json(['message' => "Round {$round->number} has no cancel waiting."], 422);
        }

        return $this->answer($order, $this->cancellation->cancel($order, $round, $request->user()), 'Round cancelled.');
    }

    /** POST /admin/orders/{order}/rounds/{round}/reject-cancel */
    public function reject(Request $request, Order $order, OrderRound $round): JsonResponse
    {
        return $this->answer($order, $this->cancellation->reject($order, $round, $request->user()), 'Cancel request rejected. The round carries on.');
    }

    /** POST /admin/orders/{order}/rounds/{round}/cancel: outright, no request needed. */
    public function directCancel(Request $request, Order $order, OrderRound $round): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->answer($order, $this->cancellation->cancel($order, $round, $request->user(), trim($validated['reason'])), 'Round cancelled.');
    }

    /**
     * GET /admin/round-cancel-requests: every round with a cancel waiting,
     * oldest first, for the Cancel requests page.
     */
    public function index(Request $request): JsonResponse
    {
        $rounds = OrderRound::query()
            ->whereNotNull('cancel_requested_at')
            ->where('status', '!=', 'cancelled')
            ->when($request->integer('branch_id'), fn ($q, $branchId) => $q->whereHas('order', fn ($o) => $o->where('branch_id', $branchId)))
            ->with(['order.branch', 'items', 'cancelRequestedBy', 'addedBy.user'])
            ->orderBy('cancel_requested_at')
            ->get();

        return response()->json(['data' => $rounds->map(fn (OrderRound $round) => [
            'order_id' => $round->order_id,
            'order_number' => $round->order?->order_number,
            'branch' => $round->order?->branch?->name,
            'customer' => $round->order?->contact_name,
            'round' => $round->number,
            'status' => $round->status,
            'total' => (float) $round->total,
            'paid_later' => (bool) $round->paid_later,
            'added_by_name' => $round->addedBy?->user?->name,
            'added_at' => $round->created_at?->toIso8601String(),
            'reason' => $round->cancel_request_reason,
            'requested_by_name' => $round->cancelRequestedBy?->name,
            'requested_at' => $round->cancel_requested_at?->toIso8601String(),
            'items' => $round->items->map(fn ($item) => [
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'name' => $item->menu_item_snapshot['name'] ?? null,
                'option_snapshot' => $item->menu_item_option_snapshot,
            ])->values(),
        ])->values()]);
    }

    private function answer(Order $order, ?string $refusal, string $done): JsonResponse
    {
        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 422);
        }

        return response()->json([
            'message' => $done,
            'data' => new OrderResource($order->fresh()->load(['customer.user', 'branch', 'items.menuItem.category', 'items.menuItemOption.media', 'payments', 'rounds.addedBy.user'])),
        ]);
    }
}
