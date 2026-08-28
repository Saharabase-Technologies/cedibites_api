<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Orders\PreparationRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderCreationService
{
    /**
     * Convert a confirmed checkout session into a real Order.
     *
     * This is the ONE place where orders are created in the entire system.
     * Called from: Hubtel callbacks, cash/card confirm endpoints, and instant flows (no_charge, manual_momo, cash-on-delivery).
     *
     * @param  \App\Models\CheckoutSession  $session
     *
     * @throws \RuntimeException
     */
    public function createFromCheckoutSession($session): Order
    {
        return DB::transaction(function () use ($session) {
            // Lock the session row to prevent double conversion.
            // We always lock (without whereNull) so we can read the
            // authoritative order_id under the row-level lock.
            $locked = DB::table('checkout_sessions')
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new \RuntimeException("Checkout session {$session->id} not found.");
            }

            if ($locked->order_id) {
                // Already converted by another process — return the existing order.
                return Order::findOrFail($locked->order_id);
            }

            // Guard: do not create orders from expired or abandoned sessions
            if (in_array($locked->status, ['expired', 'abandoned'])) {
                throw new \RuntimeException("Cannot create order from {$locked->status} session {$session->id}.");
            }

            // Generate order number inside the transaction for atomicity
            $orderNumber = app(OrderNumberService::class)->generate();

            // Resolve or create customer
            $customerId = $session->customer_id;
            if (! $customerId && $session->customer_phone) {
                $normalizedPhone = PhoneHelper::normalize($session->customer_phone);
                $sessionName = $session->customer_name ?? 'Customer';
                // The identity name is set once, when the row is first created, and
                // is never rewritten from order data. An order is an immutable
                // record of what was said at the counter; a user is a mutable
                // profile. Data flows profile -> order, never back — otherwise the
                // same phone ordering as "Philippa" renames the account (and, when
                // the phone belongs to a staff member, their staff identity too),
                // and last month's receipt starts displaying a name that was never
                // spoken. The per-order name is captured in `contact_name` below.
                $user = User::firstOrCreate(
                    ['phone' => $normalizedPhone],
                    ['name' => $sessionName]
                );

                if (! $user->customer) {
                    $user->customer()->create(['is_guest' => true]);
                    $user->load('customer');
                }
                $customerId = $user->customer->id;
            }

            $isManualEntry = (bool) $session->is_manual_entry;

            // Items are decoded here rather than after the insert because the
            // order's opening status depends on what is on it: an order with
            // nothing to cook should never be written as `received` and then
            // corrected, or the board flashes a ticket that vanishes by itself.
            $items = is_string($session->items) ? json_decode($session->items, true) : ($session->items ?? []);

            $orderSource = $this->resolveOrderSource($session);
            $menuItems = MenuItem::with('category')
                ->whereIn('id', array_filter(array_column($items, 'menu_item_id')))
                ->get();

            $initialStatus = $isManualEntry
                ? 'completed'
                : app(PreparationRouter::class)->initialStatus($menuItems, $orderSource);

            // Create the order
            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customerId,
                'branch_id' => $session->branch_id,
                'assigned_employee_id' => $session->staff_id,
                'order_type' => $session->fulfillment_type ?? 'pickup',
                'order_source' => $orderSource,
                'delivery_address' => $session->delivery_address,
                'delivery_latitude' => $session->delivery_latitude,
                'delivery_longitude' => $session->delivery_longitude,
                'contact_name' => $session->customer_name,
                'contact_phone' => $session->customer_phone ? PhoneHelper::normalize($session->customer_phone) : null,
                'delivery_note' => $session->special_instructions,
                'subtotal' => $session->subtotal,
                'delivery_fee' => $session->delivery_fee,
                'service_charge' => $session->service_charge,
                'discount' => $session->discount ?? 0,
                'promo_id' => $session->promo_id ?? null,
                'promo_name' => $session->promo_name ?? null,
                'total_amount' => $session->total_amount,
                'status' => $initialStatus,
                'recorded_at' => $isManualEntry ? $session->recorded_at : null,
                'momo_number' => $session->momo_number,
            ]);

            // Create order items from snapshot (decoded above, before the insert,
            // because the opening status depends on what is on the order)
            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $item['menu_item_id'],
                    'menu_item_option_id' => $item['menu_item_option_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'subtotal' => $item['quantity'] * $item['unit_price'],
                    'special_instructions' => $item['special_instructions'] ?? null,
                    'menu_item_snapshot' => $item['menu_item_snapshot'] ?? null,
                    'menu_item_option_snapshot' => $item['menu_item_option_snapshot'] ?? null,
                ]);
            }

            // Create payment record
            $paymentMethod = $session->payment_method;
            $paymentStatus = $this->resolvePaymentStatus($paymentMethod);

            // Restaurant collects the goods amount only; the third-party delivery
            // fee is collected by the rider on delivery (tracked via delivery_fee_status).
            Payment::create([
                'order_id' => $order->id,
                'customer_id' => $customerId,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'amount' => $order->goods_amount,
                'transaction_id' => $session->hubtel_transaction_id,
                'payment_gateway_response' => $session->payment_gateway_response,
                'paid_at' => $paymentStatus === 'completed' ? ($isManualEntry ? $session->recorded_at : now()) : null,
            ]);

            // Mark session as confirmed + link to order
            DB::table('checkout_sessions')
                ->where('id', $session->id)
                ->update([
                    'status' => 'confirmed',
                    'order_id' => $order->id,
                    'updated_at' => now(),
                ]);

            // Clear cart if this was an online (cart-based) order
            if ($session->cart_id) {
                DB::table('carts')
                    ->where('id', $session->cart_id)
                    ->update(['status' => 'completed', 'updated_at' => now()]);
            }

            // Log activity for POS/manual entries
            if ($session->staff_id) {
                $staffUser = \App\Models\Employee::find($session->staff_id)?->user;
                $branch = \App\Models\Branch::find($session->branch_id);
                $entryType = $isManualEntry ? 'Manual entry' : 'POS order';
                $description = "{$entryType} {$orderNumber} created by ".($staffUser?->name ?? 'Staff').' at '.($branch?->name ?? 'Branch')." for GHS {$session->total_amount}";

                activity()
                    ->causedBy($staffUser)
                    ->performedOn($order)
                    ->withProperties([
                        'order_number' => $orderNumber,
                        'branch_name' => $branch?->name,
                        'staff_name' => $staffUser?->name,
                        'total_amount' => $session->total_amount,
                        'is_manual_entry' => $isManualEntry,
                    ])
                    ->log($description);
            }

            $order->load(['customer.user', 'branch', 'items.menuItem', 'items.menuItemOption.media', 'payments']);

            Log::info('Order created from checkout session', [
                'order_id' => $order->id,
                'order_number' => $orderNumber,
                'session_id' => $session->id,
                'session_type' => $session->session_type,
                'payment_method' => $paymentMethod,
            ]);

            return $order;
        });
    }

    /**
     * The channel this order came through.
     *
     * Prefers what the session was told. The staff order screen has always asked
     * — phone, WhatsApp, social — and the answer used to be discarded here,
     * which recorded every call-centre order as a walk-in at the till and made
     * the channel breakdown in analytics meaningless. `pos` remains the fallback
     * for anything that does not say, which is what a branch till is.
     */
    protected function resolveOrderSource($session): string
    {
        if ($session->is_manual_entry) {
            return 'manual_entry';
        }

        if ($session->order_source) {
            return $session->order_source;
        }

        return $session->session_type === 'online' ? 'online' : 'pos';
    }

    protected function resolvePaymentStatus(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'no_charge' => 'no_charge',
            // mobile_money through gateway is already confirmed by this point
            default => 'completed',
        };
    }
}
