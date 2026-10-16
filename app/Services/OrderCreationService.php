<?php

namespace App\Services;

use App\Domain\Inventory\Recipes\RecipeDeductionService;
use App\Events\OrderBroadcastEvent;
use App\Helpers\PhoneHelper;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRound;
use App\Models\Payment;
use App\Models\User;
use App\Services\Bolt\BoltPricing;
use App\Services\Orders\PreparationRouter;
use App\Services\Payments\PaymentCollector;
use Illuminate\Support\Carbon;
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

            // Items added to an order already placed (Phase 4b). The sale ran
            // every check a new sale runs; what it makes is a round.
            if ($session->add_to_order_id) {
                return $this->appendRound($session);
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
            // Placed with nothing paid (D1). The order is made now, with no
            // payment row; payments are added as they come in. A split sale
            // is placed the same way, and the cashier takes its parts at once
            // (D41).
            $isPayLater = $session->payment_method === 'pay_later';
            $isSplit = $session->payment_method === 'split';
            $placedUnpaid = $isPayLater || $isSplit;

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

            // Bolt keeps its cut of a Bolt order, worked once on the whole
            // order. Net sales count what is left (AnalyticsQueryBuilder).
            $isBolt = $orderSource === BoltPricing::SOURCE;
            $platformCut = $isBolt
                ? app(BoltPricing::class)->commission(
                    (float) $session->subtotal,
                    round((float) $session->total_amount - (float) $session->delivery_fee, 2),
                )
                : null;

            // Create the order
            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customerId,
                'branch_id' => $session->branch_id,
                'assigned_employee_id' => $session->staff_id,
                'order_type' => $session->fulfillment_type ?? 'pickup',
                'order_source' => $orderSource,
                'platform_reference' => $isBolt ? $session->platform_reference : null,
                'platform_commission' => $platformCut['amount'] ?? 0,
                'platform_commission_rate' => $platformCut['rate'] ?? null,
                'platform_commission_base' => $platformCut['base'] ?? null,
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
                'promo_code_id' => $session->promo_code_id ?? null,
                'total_amount' => $session->total_amount,
                'status' => $initialStatus,
                'recorded_at' => $isManualEntry ? $session->recorded_at : null,
                'paid_later' => $isPayLater,
                'split_payment' => $isSplit,
                'placed_unpaid_at' => $placedUnpaid ? now() : null,
                'owing_since' => $placedUnpaid ? now() : null,
                'manual_entry_reason' => $isManualEntry ? $session->manual_entry_reason : null,
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

            if ($placedUnpaid) {
                // The kitchen cooks it before any money comes in, so its stock
                // goes now (T2). Deducting is once per order, so the payment
                // that settles it later deducts nothing more.
                DB::afterCommit(function () use ($order) {
                    try {
                        app(RecipeDeductionService::class)->deductForOrder($order);
                    } catch (\Throwable $e) {
                        Log::error('Recipe deduction failed for an order placed unpaid', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            }

            // The money is whoever rang the sale's, on the shift they were on
            // when they rang it. For a MoMo prompt paid later that is still the
            // cashier who sent it. An online customer's payment is nobody's.
            // A Bolt order's money is Bolt's until Bolt pays out, so nobody on
            // staff took it and it is on nobody's shift. The sale is still the
            // cashier's who rang it (assigned_employee_id).
            $isTill = $session->session_type === 'pos';
            $collector = app(PaymentCollector::class)->stamp(
                $isTill && ! $isBolt ? $session->staff_id : null,
                $session->branch_id,
                match (true) {
                    $isManualEntry => PaymentCollector::SOURCE_MANUAL_ENTRY,
                    $isBolt => PaymentCollector::SOURCE_BOLT,
                    $isTill => PaymentCollector::SOURCE_TILL,
                    default => PaymentCollector::SOURCE_ONLINE,
                },
                $isManualEntry && $session->recorded_at
                    ? Carbon::parse($session->recorded_at)
                    : Carbon::parse($session->created_at ?? 'now'),
            );

            // Restaurant collects the goods amount only; the third-party delivery
            // fee is collected by the rider on delivery (tracked via delivery_fee_status).
            if (! $placedUnpaid) {
                Payment::create($collector + [
                    'order_id' => $order->id,
                    'customer_id' => $customerId,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'amount' => $order->goods_amount,
                    'transaction_id' => $session->hubtel_transaction_id,
                    // Travels with the transaction id: together they say where to
                    // ask Hubtel about this payment.
                    'hubtel_account_number' => $session->hubtel_account_number,
                    'payment_gateway_response' => $session->payment_gateway_response,
                    'paid_at' => $paymentStatus === 'completed' ? ($isManualEntry ? $session->recorded_at : now()) : null,
                ]);
            }

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
     * Add a confirmed sale's items to the order it was rung against, as a
     * round (Phase 4b, D29). Called inside createFromCheckoutSession's
     * transaction, with the session row locked.
     *
     * One order number, one total, many payments: the order's total grows by
     * the round, and a round paid now adds one payment. A round placed with
     * Pay later makes the order owe again. The round reaches the kitchen as
     * its own ticket, with its own status and its own stock deduction.
     */
    protected function appendRound($session): Order
    {
        $order = Order::whereKey($session->add_to_order_id)->lockForUpdate()->firstOrFail();
        $order->load('payments');

        // The till refused this a moment ago. A MoMo prompt can take minutes,
        // and the money is in by now, so the round is still written.
        if (in_array($order->status, ['cancelled', 'cancel_requested'], true)) {
            Log::warning('A round was paid for an order that is cancelled or waiting on a cancel', [
                'order_id' => $order->id,
                'session_id' => $session->id,
            ]);
        }

        $items = is_string($session->items) ? json_decode($session->items, true) : ($session->items ?? []);
        $menuItems = MenuItem::with('category')
            ->whereIn('id', array_filter(array_column($items, 'menu_item_id')))
            ->get();

        $isPayLater = $session->payment_method === 'pay_later';
        $wasPaid = $order->balance_due <= 0;
        $roundTotal = round((float) $session->total_amount, 2);

        $round = OrderRound::create([
            'order_id' => $order->id,
            'number' => max(1, (int) $order->rounds()->max('number')) + 1,
            'added_by' => $session->staff_id,
            // Its own kitchen status. A round with nothing to cook is done as
            // it is added, as a sale of the same items would be.
            'status' => app(PreparationRouter::class)->initialStatus($menuItems, 'pos'),
            'paid_later' => $isPayLater,
            'subtotal' => $session->subtotal,
            'total' => $roundTotal,
            'checkout_session_id' => $session->id,
        ]);

        foreach ($items as $item) {
            OrderItem::create([
                'order_id' => $order->id,
                'order_round_id' => $round->id,
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

        $order->subtotal = round((float) $order->subtotal + (float) $session->subtotal, 2);
        $order->total_amount = round((float) $order->total_amount + $roundTotal, 2);

        if ($isPayLater) {
            // Its meaning widens to "some of this order was placed with Pay
            // later", so the Not paid list, the boards and the cashier's copy
            // of the receipt follow it with no new rule (D2).
            $order->paid_later = true;

            // A paid order that starts owing again starts a new spell: the
            // one-hour alert counts from now and may go once more (D10, D32).
            if ($wasPaid || $order->owing_since === null) {
                $order->owing_since = now();
                $order->unpaid_alerted_at = null;
            }
        }

        $order->save();

        if (! $isPayLater) {
            $collector = app(PaymentCollector::class)->stamp(
                $session->staff_id,
                $session->branch_id,
                PaymentCollector::SOURCE_TILL,
                Carbon::parse($session->created_at ?? 'now'),
            );
            $paymentStatus = $this->resolvePaymentStatus($session->payment_method);

            Payment::create($collector + [
                'order_id' => $order->id,
                'order_round_id' => $round->id,
                'customer_id' => $order->customer_id,
                'payment_method' => $session->payment_method,
                'payment_status' => $paymentStatus,
                'amount' => $roundTotal,
                // Cash handed over, when there was change, as Take payment
                // keeps it. The receipt's payments block prints it (D31).
                'amount_tendered' => $session->payment_method === 'cash' && (float) $session->amount_paid > $roundTotal
                    ? (float) $session->amount_paid
                    : null,
                'transaction_id' => $session->hubtel_transaction_id,
                'hubtel_account_number' => $session->hubtel_account_number,
                'payment_gateway_response' => $session->payment_gateway_response,
                'paid_at' => $paymentStatus === 'completed' ? now() : null,
            ]);
        }

        DB::table('checkout_sessions')
            ->where('id', $session->id)
            ->update([
                'status' => 'confirmed',
                'order_id' => $order->id,
                'updated_at' => now(),
            ]);

        // Once per round, paid now or Pay later: the kitchen cooks it either way.
        DB::afterCommit(function () use ($round, $order) {
            try {
                app(RecipeDeductionService::class)->deductForRound($round);
            } catch (\Throwable $e) {
                Log::error('Recipe deduction failed for a round', [
                    'order_id' => $order->id,
                    'round_id' => $round->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // The observer broadcasts on a status change only. The order's own
            // status has not moved, so the boards are told here.
            try {
                OrderBroadcastEvent::dispatch($order->fresh(), 'updated');
            } catch (\Throwable $e) {
                Log::warning('Order broadcast failed for a round', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        if ($session->staff_id) {
            $staffUser = \App\Models\Employee::find($session->staff_id)?->user;

            activity()
                ->causedBy($staffUser)
                ->performedOn($order)
                ->withProperties([
                    'order_number' => $order->order_number,
                    'round' => $round->number,
                    'round_total' => $roundTotal,
                    'payment_method' => $session->payment_method,
                ])
                ->log("Round {$round->number} added to {$order->order_number} by ".($staffUser?->name ?? 'Staff')." for GHS {$roundTotal}");
        }

        return $order->load(['customer.user', 'branch', 'items.menuItem', 'items.menuItemOption.media', 'payments', 'rounds.addedBy.user']);
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
