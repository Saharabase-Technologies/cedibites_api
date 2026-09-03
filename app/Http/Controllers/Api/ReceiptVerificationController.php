<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/**
 * "Is this receipt real?", asked by whoever is holding it.
 *
 * Deliberately public. The person scanning the QR is a customer with a phone,
 * not a member of staff, so there is nobody to authenticate — which is the
 * whole difference between this and the purchase-order verifier, where the
 * scanner is an employee and the route sits behind a permission.
 *
 * That makes the code itself the entire security boundary, and it is why it is
 * random rather than derived from the order number. A guessable code would let
 * anyone print a convincing forgery whose QR points at a real order, and the
 * scan would confirm it. Nothing here accepts an order id or an order number,
 * only the code, and a wrong one is a flat 404 with no hint whether the order
 * exists.
 */
class ReceiptVerificationController extends Controller
{
    public function verify(string $code): JsonResponse
    {
        $order = Order::with([
            'branch', 'items.menuItem', 'items.menuItemOption', 'assignedEmployee.user', 'payments',
        ])
            ->where('receipt_verification_code', $code)
            ->first();

        if (! $order) {
            return response()->error('No receipt matches this code.', 404);
        }

        return response()->success([
            'verified' => true,
            'order_number' => $order->order_number,
            'placed_at' => $order->created_at?->toISOString(),
            'status' => $order->status,
            // A receipt for an order that was later cancelled or refunded is
            // still a receipt we issued, and saying so is the honest answer.
            // Hiding it would let a cancelled slip pass as a live sale.
            'is_cancelled' => in_array($order->status, ['cancelled', 'refunded'], true),
            'branch' => [
                'name' => $order->branch?->name,
                'address' => $order->branch?->address,
                'phone' => $order->branch?->phone,
            ],
            'customer' => [
                'name' => $order->contact_name,
                'phone' => $order->contact_phone,
            ],
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->menuItem?->name
                    ?? data_get($item->menu_item_snapshot, 'name')
                    ?? 'Item',
                'option' => $item->menuItemOption?->display_name
                    ?? $item->menuItemOption?->option_label,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->unit_price * (int) $item->quantity,
            ])->values(),
            'subtotal' => (float) $order->subtotal,
            'delivery_fee' => (float) $order->delivery_fee,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total_amount,
            'payment_method' => $order->payment_method,
            // Payment status lives on the payment rows, not the order, so this
            // is derived the same way the `paid` scope derives it. Reading
            // $order->payment_status would silently have been null.
            'is_paid' => $order->payments
                ->whereIn('payment_status', ['completed', 'no_charge'])
                ->isNotEmpty(),
            'order_type' => $order->order_type,
            'served_by' => $order->assignedEmployee?->user?->name,
            // How many slips exist for this sale. A customer holding "Reprint 3"
            // can see that three were run, which is the point of numbering them.
            'print_count' => (int) $order->receipt_print_count,
        ]);
    }
}
