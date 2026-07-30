<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Services\HubtelPaymentService;
use App\Services\OrderCreationService;
use App\Services\PromoResolutionService;
use App\Services\SystemSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckoutSessionController extends Controller
{
    use \App\Http\Controllers\Api\Concerns\RefusesShortStock;

    public function __construct(
        protected OrderCreationService $orderCreationService,
        protected SystemSettingService $settingService,
    ) {}

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ONLINE (Customer) Endpoints — under cart.identity middleware
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * POST /checkout-sessions — create a checkout session from the customer's cart.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'order_type' => ['required', 'in:delivery,pickup'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'string', 'min:5'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'special_instructions' => ['nullable', 'string'],
            'payment_method' => ['required', 'in:mobile_money,cash'],
            'momo_number' => ['nullable', 'string', 'max:20'],
        ]);

        // ── Branch availability checks ──────────────────────────────────
        $branch = Branch::with(['orderTypes', 'paymentMethods'])->findOrFail($validated['branch_id']);

        if (! $branch->is_active) {
            return response()->json(['message' => 'This branch is currently inactive and not accepting orders.'], 422);
        }

        if (! $branch->isCurrentlyOpen()) {
            return response()->json(['message' => 'This branch is currently closed. Please check back during operating hours.'], 422);
        }

        // Validate the selected order type is enabled for this branch
        $orderTypeEnabled = $branch->orderTypes
            ->where('order_type', $validated['order_type'])
            ->where('is_enabled', true)
            ->isNotEmpty();

        if (! $orderTypeEnabled) {
            $label = ucfirst($validated['order_type']);

            return response()->json(['message' => "{$label} is not available at this branch."], 422);
        }

        // Validate the selected payment method is enabled for this branch
        // Map frontend payment keys to DB payment keys
        $paymentMethodMap = [
            'mobile_money' => 'momo',
            'cash' => 'cash_on_delivery',
        ];
        $dbPaymentKey = $paymentMethodMap[$validated['payment_method']] ?? $validated['payment_method'];

        $paymentEnabled = $branch->paymentMethods
            ->where('payment_method', $dbPaymentKey)
            ->where('is_enabled', true)
            ->isNotEmpty();

        if (! $paymentEnabled) {
            return response()->json(['message' => 'This payment method is not available at the selected branch.'], 422);
        }

        // Resolve cart identity
        $identity = $this->resolveCartIdentity($request);

        $cartQuery = Cart::with(['items.menuItem', 'items.menuItemOption', 'branch'])
            ->where('status', 'active')
            ->lockForUpdate();

        if ($identity['customer_id'] !== null) {
            $cartQuery->where('customer_id', $identity['customer_id']);
        } else {
            $cartQuery->whereNull('customer_id')->where('session_id', $identity['session_id']);
        }

        $cart = $cartQuery->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty. Add items before placing an order.'], 422);
        }

        if ((int) $validated['branch_id'] !== (int) $cart->branch_id) {
            return response()->json(['message' => 'Cart branch does not match selected branch.'], 422);
        }

        // Validate cart items still exist and have valid prices
        $itemSnapshots = [];
        $subtotal = 0;

        foreach ($cart->items as $cartItem) {
            $mi = $cartItem->menuItem;
            if (! $mi) {
                return response()->json(['message' => 'Menu item no longer available.'], 422);
            }

            $opt = $cartItem->menuItemOption;
            $unitPrice = $opt ? (float) $opt->price : (float) ($mi->options->first()?->price ?? 0);
            $lineTotal = $cartItem->quantity * $unitPrice;
            $subtotal += $lineTotal;

            $itemSnapshots[] = [
                'menu_item_id' => $cartItem->menu_item_id,
                'menu_item_option_id' => $cartItem->menu_item_option_id,
                'quantity' => $cartItem->quantity,
                'unit_price' => $unitPrice,
                'special_instructions' => $cartItem->special_instructions,
                'menu_item_snapshot' => $mi ? [
                    'id' => $mi->id,
                    'name' => $mi->name,
                    'description' => $mi->description,
                ] : null,
                'menu_item_option_snapshot' => $opt ? [
                    'id' => $opt->id,
                    'option_key' => $opt->option_key,
                    'option_label' => $opt->option_label,
                    'display_name' => $opt->display_name,
                    'price' => (float) $opt->price,
                    'image_url' => $opt->getFirstMediaUrl('menu-item-options') ?: null,
                ] : null,
            ];
        }

        // Resolve best applicable promo for this cart
        $promoService = app(PromoResolutionService::class);
        $itemIds = array_column($itemSnapshots, 'menu_item_id');
        $resolvedPromo = $promoService->resolve($itemIds, (string) $validated['branch_id'], $subtotal);
        $discount = $resolvedPromo ? $promoService->calculateDiscount($resolvedPromo, $subtotal) : 0;

        // Calculate totals — service charge on customer orders (percentage with cap)
        $serviceChargeEnabled = $this->settingService->getBoolean('service_charge_enabled', true);
        $serviceCharge = 0;
        if ($serviceChargeEnabled) {
            $serviceChargePercent = $this->settingService->getInteger('service_charge_percent', 1);
            $serviceChargeCap = $this->settingService->getInteger('service_charge_cap', 5);
            $serviceCharge = round($subtotal * ($serviceChargePercent / 100), 2);
            if ($serviceChargeCap > 0 && $serviceCharge > $serviceChargeCap) {
                $serviceCharge = (float) $serviceChargeCap;
            }
        }
        $deliveryFee = 0; // Delivery fees temporarily disabled
        $totalAmount = $subtotal - $discount + $serviceCharge + $deliveryFee;

        $sessionToken = Str::uuid()->toString();

        $session = CheckoutSession::create([
            'session_token' => $sessionToken,
            'branch_id' => $validated['branch_id'],
            'session_type' => 'online',
            'status' => 'pending',
            'customer_name' => $validated['customer_name'],
            'customer_phone' => PhoneHelper::normalize($validated['customer_phone']),
            'delivery_address' => $validated['delivery_address'] ?? null,
            'delivery_latitude' => $validated['delivery_latitude'] ?? null,
            'delivery_longitude' => $validated['delivery_longitude'] ?? null,
            'special_instructions' => $validated['special_instructions'] ?? null,
            'fulfillment_type' => $validated['order_type'],
            'payment_method' => $validated['payment_method'],
            'momo_number' => isset($validated['momo_number']) ? PhoneHelper::normalize($validated['momo_number']) : null,
            'items' => $itemSnapshots,
            'subtotal' => $subtotal,
            'service_charge' => $serviceCharge,
            'delivery_fee' => $deliveryFee,
            'discount' => $discount,
            'promo_id' => $resolvedPromo?->id,
            'promo_name' => $resolvedPromo?->name,
            'total_amount' => $totalAmount,
            'cart_id' => $cart->id,
            'customer_id' => $identity['customer_id'],
            'expires_at' => now()->addMinutes(5),
        ]);

        // Cash on delivery → create order immediately
        if ($validated['payment_method'] === 'cash') {
            $order = $this->orderCreationService->createFromCheckoutSession($session);

            return response()->json([
                'session_token' => $sessionToken,
                'status' => 'confirmed',
                'order' => new OrderResource($order),
            ], 201);
        }

        // Mobile money → initiate Hubtel standard payment
        try {
            $hubtel = app(HubtelPaymentService::class);
            $branch = Branch::find($validated['branch_id']);

            // Hubtel standard uses a temporary order-like object for the payload
            $frontendUrl = config('app.frontend_url');
            $hubtelResult = $hubtel->initializeTransaction([
                'order' => (object) [
                    'id' => $session->id,
                    'order_number' => $sessionToken,
                    'total_amount' => $totalAmount,
                    'delivery_fee' => $deliveryFee,
                    'customer_id' => $identity['customer_id'],
                    'contact_name' => $validated['customer_name'],
                    'contact_phone' => PhoneHelper::toInternational($validated['customer_phone']),
                ],
                'description' => "Order at {$branch->name}",
                'customer_name' => $validated['customer_name'],
                'customer_phone' => PhoneHelper::toInternational($validated['customer_phone']),
                'return_url' => "{$frontendUrl}/checkout/return?session={$sessionToken}",
                'cancellation_url' => "{$frontendUrl}/checkout/cancelled?session={$sessionToken}",
            ]);

            $session->update([
                'status' => 'payment_initiated',
                'hubtel_transaction_id' => $hubtelResult['checkoutId'] ?? null,
                'hubtel_checkout_url' => $hubtelResult['checkoutUrl'] ?? null,
                'payment_gateway_response' => $hubtelResult,
            ]);

            return response()->json([
                'session_token' => $sessionToken,
                'status' => 'payment_initiated',
                'checkout_url' => $hubtelResult['checkoutUrl'] ?? null,
            ], 201);
        } catch (\Exception $e) {
            $session->update(['status' => 'failed']);
            Log::error('Checkout session payment initiation failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /checkout-sessions/{token} — poll session status.
     */
    public function show(string $token): JsonResponse
    {
        $session = CheckoutSession::where('session_token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        // Auto-expire
        if ($session->isExpired() && in_array($session->status, ['pending', 'payment_initiated'])) {
            $session->update(['status' => 'expired']);
        }

        $isRecoverable = in_array($session->status, ['failed', 'expired', 'payment_initiated', 'pending']);
        $isMomo = $session->payment_method === 'mobile_money';

        $data = [
            'session_token' => $session->session_token,
            'status' => $session->status,
            'payment_method' => $session->payment_method,
            'total_amount' => $session->total_amount,
            'momo_number' => $session->momo_number,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'session_type' => $session->session_type,
            'can_retry' => $isRecoverable && $isMomo,
            'can_change_payment' => $isRecoverable && ! $session->order_id,
            'can_change_number' => $isRecoverable && $isMomo,
        ];

        if ($session->status === 'confirmed' && $session->order_id) {
            $order = $session->order()->with(['customer.user', 'branch', 'items.menuItem', 'items.menuItemOption.media', 'payments'])->first();
            $data['order'] = new OrderResource($order);
        }

        return response()->json($data);
    }

    /**
     * DELETE /checkout-sessions/{token} — abandon session.
     */
    public function destroy(string $token): JsonResponse
    {
        $session = CheckoutSession::where('session_token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if ($session->status === 'confirmed') {
            return response()->json(['message' => 'Cannot cancel a confirmed session.'], 422);
        }

        $session->update(['status' => 'expired']);

        return response()->json(['message' => 'Session cancelled.']);
    }

    /**
     * POST /pos/checkout-sessions/{token}/cancel — cancel a pending session (POST alternative to DELETE).
     */
    public function cancel(string $token): JsonResponse
    {
        return $this->destroy($token);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // POS Endpoints — under auth:sanctum middleware
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * POST /pos/checkout-sessions — create from POS items.
     */
    public function posStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'items.*.menu_item_option_id' => ['nullable', 'integer', 'exists:menu_item_options,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,card,wallet,ghqr,no_charge,manual_momo'],
            'is_manual_entry' => ['sometimes', 'boolean'],
            'recorded_at' => ['required_if:is_manual_entry,true', 'nullable', 'date', 'before_or_equal:now'],
            'momo_reference' => ['nullable', 'string', 'max:100'],
            // `pickup` was missing, though it is one of the two types a branch can
            // actually enable (branch_order_types) and one of the four the orders
            // table allows. A till only ever sends dine_in or takeaway, so nothing
            // noticed — but a phone order the caller collects themselves is a
            // pickup, and calling it a takeaway loses the distinction the branch
            // needs to know whether anyone is coming.
            'fulfillment_type' => ['required', 'string', 'in:dine_in,takeaway,pickup,delivery'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'customer_notes' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'momo_number' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'regex:/^(0[0-9]{9}|\+?233[0-9]{9})$/'],

            // The channel this order came through. Optional, defaulting to the
            // till, so an existing POS client that does not send it keeps
            // recording `pos`. The call centre sends the channel it took the
            // call on. See OrderCreationService::resolveOrderSource.
            'order_source' => ['nullable', 'string', 'in:pos,phone,whatsapp,social_media'],

            // No stock, no sale — and its exception. Honoured only for a holder
            // of inventory.stock_gate.override.
            'override_stock_gate' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $employee = $user->employee;

        if (! $employee || $employee->status !== \App\Enums\EmployeeStatus::Active) {
            return response()->json(['message' => 'Employee record not found or inactive.'], 403);
        }

        $branchId = $validated['branch_id'];
        $this->verifyStaffAuthorization($employee, $branchId);

        $branch = Branch::findOrFail($branchId);

        // Block POS orders when branch is closed unless extended order access is enabled
        if (! $branch->isCurrentlyOpen() && ! $branch->isExtendedOrderAllowed()) {
            return response()->json([
                'code' => 'branch_closed',
                'message' => 'This branch is currently closed. To place orders after hours, ask an administrator to enable extended order access from the admin settings.',
            ], 422);
        }

        // No stock, no sale.
        //
        // This is the path the till actually takes. PosOrderController::store
        // carries the same check, but the terminal creates a checkout session
        // and confirms it — so gating that controller alone left the real POS
        // flow wide open, and a sale of 23 portions went through against a
        // balance of 6 with the gate "live".
        $stockRefusal = $this->refuseIfShort($request, $branchId, $validated['items'], $employee);
        if ($stockRefusal !== null) {
            return $stockRefusal;
        }

        // Validate menu items belong to branch + resolve DB prices
        $menuItems = $this->validateMenuItems($validated['items'], $branchId);
        $resolvedItems = $this->resolveItemPrices($validated['items'], $menuItems);

        // Build snapshots
        $itemSnapshots = [];
        $subtotal = 0;
        foreach ($resolvedItems as $item) {
            $mi = $menuItems->get($item['menu_item_id']);
            $opt = isset($item['menu_item_option_id']) ? MenuItemOption::find($item['menu_item_option_id']) : null;
            $lineTotal = $item['quantity'] * $item['unit_price'];
            $subtotal += $lineTotal;

            $itemSnapshots[] = [
                'menu_item_id' => $item['menu_item_id'],
                'menu_item_option_id' => $item['menu_item_option_id'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'special_instructions' => null,
                'menu_item_snapshot' => $mi ? [
                    'id' => $mi->id,
                    'name' => $mi->name,
                    'description' => $mi->description,
                ] : null,
                'menu_item_option_snapshot' => $opt ? [
                    'id' => $opt->id,
                    'option_key' => $opt->option_key,
                    'option_label' => $opt->option_label,
                    'display_name' => $opt->display_name,
                    'price' => (float) $opt->price,
                    'image_url' => $opt->getFirstMediaUrl('menu-item-options') ?: null,
                ] : null,
            ];
        }

        $discount = (float) ($validated['discount'] ?? 0);

        // Server-side promo validation: resolve the best promo and cap discount
        $promoService = app(PromoResolutionService::class);
        $itemIdsForPromo = array_column($itemSnapshots, 'menu_item_id');
        $resolvedPromo = $promoService->resolve($itemIdsForPromo, (string) $branchId, $subtotal);
        $maxAllowedDiscount = $resolvedPromo ? $promoService->calculateDiscount($resolvedPromo, $subtotal) : 0;
        // Cap the POS discount to the resolved promo discount (prevent arbitrary amounts)
        $discount = min($discount, $maxAllowedDiscount);

        // Delivery fee only applies to delivery orders (staff-entered, e.g. phone-in deliveries)
        $deliveryFee = $validated['fulfillment_type'] === 'delivery'
            ? (float) ($validated['delivery_fee'] ?? 0)
            : 0;

        $subtotalAfterDiscount = $subtotal - $discount;
        // No service charge for POS
        $totalAmount = $subtotalAfterDiscount + $deliveryFee;

        $isManualEntry = (bool) ($validated['is_manual_entry'] ?? false);

        // Validate manual entry date toggle
        if ($isManualEntry && $validated['recorded_at']) {
            $dateEnabled = $this->settingService->getBoolean('manual_entry_date_enabled', false);
            if (! $dateEnabled) {
                // Only time-only mode — date must be today
                $recordedDate = \Carbon\Carbon::parse($validated['recorded_at']);
                if (! $recordedDate->isToday()) {
                    return response()->json([
                        'message' => 'Manual entry date selection is disabled. Only today\'s date is allowed.',
                    ], 422);
                }
            }
        }

        $paymentMethod = $validated['payment_method'];

        // No charge — admin only
        if ($paymentMethod === 'no_charge') {
            if (! $user->hasAnyRole([Role::Admin, Role::TechAdmin])) {
                return response()->json(['message' => 'Only administrators can create no-charge orders.'], 403);
            }
        }

        $sessionToken = Str::uuid()->toString();

        $session = CheckoutSession::create([
            'session_token' => $sessionToken,
            'branch_id' => $branchId,
            'session_type' => 'pos',
            // Which channel, as opposed to session_type's online-or-not.
            'order_source' => $validated['order_source'] ?? 'pos',
            'status' => 'pending',
            'customer_name' => $validated['contact_name'],
            'customer_phone' => PhoneHelper::normalize($validated['contact_phone']),
            'special_instructions' => $validated['customer_notes'] ?? null,
            'fulfillment_type' => $validated['fulfillment_type'],
            'payment_method' => $paymentMethod,
            'momo_number' => isset($validated['momo_number']) ? PhoneHelper::normalize($validated['momo_number']) : null,
            'items' => $itemSnapshots,
            'subtotal' => $subtotal,
            'service_charge' => 0,
            'delivery_fee' => $deliveryFee,
            'discount' => $discount,
            'promo_id' => $resolvedPromo?->id,
            'promo_name' => $resolvedPromo?->name,
            'total_amount' => $totalAmount,
            'staff_id' => $employee->id,
            'is_manual_entry' => $isManualEntry,
            'recorded_at' => $isManualEntry ? $validated['recorded_at'] : null,
            'momo_reference' => $validated['momo_reference'] ?? null,
            'expires_at' => now()->addMinutes(5),
        ]);

        // Determine flow based on payment method
        return match ($paymentMethod) {
            'mobile_money' => $this->posMomoFlow($session, $validated, $employee, $branchId),
            'cash', 'card' => $this->posAwaitConfirmFlow($session),
            'manual_momo' => $this->posInstantFlow($session),
            'no_charge' => $this->posInstantFlow($session),
            'wallet', 'ghqr' => $this->posInstantFlow($session),
            default => response()->json(['message' => 'Unsupported payment method.'], 422),
        };
    }

    /**
     * POST /pos/checkout-sessions/{token}/confirm-cash
     */
    public function confirmCash(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $session = CheckoutSession::where('session_token', $token)
            ->where('payment_method', 'cash')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        // Verify the requesting employee belongs to this session's branch
        $employee = $request->user()->employee;
        if ($employee) {
            $this->verifyStaffAuthorization($employee, $session->branch_id);
        }

        if ($session->order_id) {
            $order = $session->order()->with(['customer.user', 'branch', 'items.menuItem', 'payments'])->first();

            return response()->json([
                'session_token' => $session->session_token,
                'status' => 'confirmed',
                'order' => new OrderResource($order),
            ]);
        }

        if (in_array($session->status, ['confirmed', 'failed', 'abandoned', 'expired'])) {
            return response()->json(['message' => "Cannot confirm cash — session is {$session->status}."], 422);
        }

        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired']);

            return response()->json(['message' => 'Session has expired.'], 422);
        }

        $session->update(['amount_paid' => $validated['amount_paid']]);

        $order = $this->orderCreationService->createFromCheckoutSession($session);

        return response()->json([
            'session_token' => $session->session_token,
            'status' => 'confirmed',
            'order' => new OrderResource($order),
            'items' => $session->items,
            'customer_name' => $session->customer_name,
            'customer_phone' => $session->customer_phone,
            'total_amount' => $session->total_amount,
            'payment_method' => $session->payment_method,
            'change' => round($validated['amount_paid'] - (float) $session->total_amount, 2),
        ], 201);
    }

    /**
     * POST /pos/checkout-sessions/{token}/confirm-card
     */
    public function confirmCard(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $session = CheckoutSession::where('session_token', $token)
            ->where('payment_method', 'card')
            ->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        // Verify the requesting employee belongs to this session's branch
        $employee = $request->user()->employee;
        if ($employee) {
            $this->verifyStaffAuthorization($employee, $session->branch_id);
        }

        if ($session->order_id) {
            $order = $session->order()->with(['customer.user', 'branch', 'items.menuItem', 'payments'])->first();

            return response()->json([
                'session_token' => $session->session_token,
                'status' => 'confirmed',
                'order' => new OrderResource($order),
            ]);
        }

        if (in_array($session->status, ['confirmed', 'failed', 'abandoned', 'expired'])) {
            return response()->json(['message' => "Cannot confirm card — session is {$session->status}."], 422);
        }

        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->update(['status' => 'expired']);

            return response()->json(['message' => 'Session has expired.'], 422);
        }

        $session->update(['amount_paid' => $validated['amount_paid']]);

        $order = $this->orderCreationService->createFromCheckoutSession($session);

        return response()->json([
            'session_token' => $session->session_token,
            'status' => 'confirmed',
            'order' => new OrderResource($order),
            'items' => $session->items,
            'customer_name' => $session->customer_name,
            'customer_phone' => $session->customer_phone,
            'total_amount' => $session->total_amount,
            'payment_method' => $session->payment_method,
        ], 201);
    }

    /**
     * POST /checkout-sessions/{token}/retry-payment — retry MoMo.
     */
    public function retryPayment(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'momo_number' => ['nullable', 'string', 'max:20'],
        ]);

        $session = CheckoutSession::where('session_token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if ($session->status === 'confirmed') {
            return response()->json(['message' => 'Session already confirmed.'], 422);
        }

        if (in_array($session->status, ['expired', 'abandoned'])) {
            return response()->json(['message' => 'This payment session has expired. Please start a new order.'], 410);
        }

        // Enforce 5-minute cooldown between MoMo prompts
        if ($session->last_momo_sent_at) {
            $secondsSinceLast = (int) $session->last_momo_sent_at->diffInSeconds(now());
            $cooldown = 300; // 5 minutes — matches MoMo USSD prompt duration

            if ($secondsSinceLast < $cooldown) {
                $remaining = $cooldown - $secondsSinceLast;

                return response()->json([
                    'message' => "Please wait {$remaining} seconds before re-sending the MoMo prompt.",
                    'cooldown_remaining' => $remaining,
                ], 429);
            }
        }

        $momoNumber = isset($validated['momo_number'])
            ? PhoneHelper::normalize($validated['momo_number'])
            : $session->momo_number;

        if (! $momoNumber) {
            return response()->json(['message' => 'MoMo number is required.'], 422);
        }

        try {
            $hubtel = app(HubtelPaymentService::class);

            if ($session->session_type === 'pos') {
                // POS → RMP
                $branch = Branch::find($session->branch_id);
                $result = $hubtel->initializeReceiveMoney([
                    'order' => (object) [
                        'id' => $session->id,
                        'order_number' => $session->session_token,
                        'total_amount' => $session->total_amount,
                        'delivery_fee' => $session->delivery_fee,
                        'customer_id' => null,
                        'contact_name' => $session->customer_name,
                        'contact_phone' => PhoneHelper::toInternational($momoNumber),
                    ],
                    'description' => "POS Order - {$branch->name}",
                    'customer_name' => $session->customer_name,
                    'customer_phone' => PhoneHelper::toLocal($momoNumber),
                ]);

                $session->update([
                    'status' => 'payment_initiated',
                    'momo_number' => $momoNumber,
                    'hubtel_transaction_id' => $result['transactionId'] ?? null,
                    'payment_gateway_response' => $result,
                    'expires_at' => now()->addMinutes(5),
                    'last_momo_sent_at' => now(),
                ]);
            } else {
                // Online → Standard
                $branch = Branch::find($session->branch_id);
                $result = $hubtel->initializeTransaction([
                    'order' => (object) [
                        'id' => $session->id,
                        'order_number' => $session->session_token,
                        'total_amount' => $session->total_amount,
                        'delivery_fee' => $session->delivery_fee,
                        'customer_id' => $session->customer_id,
                        'contact_name' => $session->customer_name,
                        'contact_phone' => PhoneHelper::toInternational($momoNumber),
                    ],
                    'description' => "Order at {$branch->name}",
                    'customer_name' => $session->customer_name,
                    'customer_phone' => PhoneHelper::toInternational($momoNumber),
                ]);

                $session->update([
                    'status' => 'payment_initiated',
                    'momo_number' => $momoNumber,
                    'hubtel_transaction_id' => $result['checkoutId'] ?? null,
                    'hubtel_checkout_url' => $result['checkoutUrl'] ?? null,
                    'payment_gateway_response' => $result,
                    'expires_at' => now()->addMinutes(5),
                    'last_momo_sent_at' => now(),
                ]);
            }

            return response()->json([
                'session_token' => $session->session_token,
                'status' => $session->fresh()->status,
                'checkout_url' => $session->hubtel_checkout_url,
            ]);
        } catch (\Exception $e) {
            Log::error('Retry payment failed', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /checkout-sessions/{token}/change-payment — switch payment method.
     */
    public function changePayment(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:cash,mobile_money,card'],
            'momo_number' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'max:20'],
        ]);

        $session = CheckoutSession::where('session_token', $token)->first();

        if (! $session) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        if ($session->status === 'confirmed') {
            return response()->json(['message' => 'Session already confirmed.'], 422);
        }

        if (in_array($session->status, ['expired', 'abandoned'])) {
            return response()->json(['message' => 'This payment session has expired. Please start a new order.'], 410);
        }

        $newMethod = $validated['payment_method'];
        $session->update([
            'payment_method' => $newMethod,
            'status' => 'pending',
        ]);

        // Online cash-on-delivery: create order immediately
        if ($session->session_type === 'online' && $newMethod === 'cash') {
            $order = $this->orderCreationService->createFromCheckoutSession($session);

            return response()->json([
                'session_token' => $session->session_token,
                'status' => 'confirmed',
                'order' => new OrderResource($order),
            ], 201);
        }

        // Switching to MoMo: auto-initiate the payment flow
        if ($newMethod === 'mobile_money' && ! empty($validated['momo_number'])) {
            $momoNumber = PhoneHelper::normalize($validated['momo_number']);
            $isNewNumber = $session->momo_number !== null && $session->momo_number !== $momoNumber;

            // Enforce 5-minute cooldown between MoMo prompts (only for the SAME number)
            // Changing to a different number should trigger a fresh prompt immediately
            if (! $isNewNumber && $session->last_momo_sent_at) {
                $secondsSinceLast = (int) $session->last_momo_sent_at->diffInSeconds(now());
                $cooldown = 300;

                if ($secondsSinceLast < $cooldown) {
                    $remaining = $cooldown - $secondsSinceLast;

                    return response()->json([
                        'message' => "Please wait {$remaining} seconds before re-sending the MoMo prompt.",
                        'cooldown_remaining' => $remaining,
                    ], 429);
                }
            }

            try {
                $hubtel = app(HubtelPaymentService::class);
                $branch = Branch::find($session->branch_id);

                if ($session->session_type === 'pos') {
                    $result = $hubtel->initializeReceiveMoney([
                        'order' => (object) [
                            'id' => $session->id,
                            'order_number' => $session->session_token,
                            'total_amount' => $session->total_amount,
                            'delivery_fee' => $session->delivery_fee,
                            'customer_id' => null,
                            'contact_name' => $session->customer_name,
                            'contact_phone' => PhoneHelper::toInternational($momoNumber),
                        ],
                        'description' => "POS Order - {$branch->name}",
                        'customer_name' => $session->customer_name,
                        'customer_phone' => PhoneHelper::toLocal($momoNumber),
                    ]);

                    $session->update([
                        'status' => 'payment_initiated',
                        'momo_number' => $momoNumber,
                        'hubtel_transaction_id' => $result['transactionId'] ?? null,
                        'payment_gateway_response' => $result,
                        'expires_at' => now()->addMinutes(5),
                        'last_momo_sent_at' => now(),
                    ]);
                } else {
                    $result = $hubtel->initializeTransaction([
                        'order' => (object) [
                            'id' => $session->id,
                            'order_number' => $session->session_token,
                            'total_amount' => $session->total_amount,
                            'delivery_fee' => $session->delivery_fee,
                            'customer_id' => $session->customer_id,
                            'contact_name' => $session->customer_name,
                            'contact_phone' => PhoneHelper::toInternational($momoNumber),
                        ],
                        'description' => "Order at {$branch->name}",
                        'customer_name' => $session->customer_name,
                        'customer_phone' => PhoneHelper::toInternational($momoNumber),
                    ]);

                    $session->update([
                        'status' => 'payment_initiated',
                        'momo_number' => $momoNumber,
                        'hubtel_transaction_id' => $result['checkoutId'] ?? null,
                        'hubtel_checkout_url' => $result['checkoutUrl'] ?? null,
                        'payment_gateway_response' => $result,
                        'expires_at' => now()->addMinutes(5),
                        'last_momo_sent_at' => now(),
                    ]);
                }

                return response()->json([
                    'session_token' => $session->session_token,
                    'status' => $session->fresh()->status,
                    'payment_method' => $newMethod,
                    'checkout_url' => $session->hubtel_checkout_url,
                ]);
            } catch (\Exception $e) {
                Log::error('Change payment to MoMo failed', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => 'Failed to initiate MoMo payment: '.$e->getMessage()], 400);
            }
        }

        // POS cash/card: frontend opens respective modal, no order yet
        return response()->json([
            'session_token' => $session->session_token,
            'status' => 'pending',
            'payment_method' => $newMethod,
        ]);
    }

    /**
     * GET /pos/checkout-sessions — list pending sessions for current staff's branch.
     */
    public function posIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 403);
        }

        // Null means every branch. A company-wide operator holds no branch
        // assignment, so plucking their pivot returns the empty set and the
        // drawer showed "0 sessions awaiting payment" however many were waiting
        // — including the ones they had just started themselves.
        // See User::isCompanyWide.
        $branchIds = $user->isCompanyWide()
            ? null
            : $employee->branches()->pluck('branches.id');

        $query = CheckoutSession::where('session_type', 'pos')
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereIn('status', ['pending', 'payment_initiated'])
                        ->where('expires_at', '>', now());
                })->orWhere(function ($q2) {
                    // Include recently failed sessions so the POS can show decline notifications
                    $q2->where('status', 'failed')
                        ->where('updated_at', '>', now()->subMinutes(2));
                });
            })
            ->latest();

        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->query('branch_id'));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // Private helpers
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function posMomoFlow(CheckoutSession $session, array $validated, $employee, int $branchId): JsonResponse
    {
        try {
            $hubtel = app(HubtelPaymentService::class);
            $branch = Branch::find($branchId);

            $result = $hubtel->initializeReceiveMoney([
                'order' => (object) [
                    'id' => $session->id,
                    'order_number' => $session->session_token,
                    'total_amount' => $session->total_amount,
                    'delivery_fee' => $session->delivery_fee,
                    'customer_id' => null,
                    'contact_name' => $session->customer_name,
                    'contact_phone' => PhoneHelper::toInternational($session->momo_number ?? $validated['momo_number']),
                ],
                'description' => "POS Order - {$branch->name}",
                'customer_name' => $session->customer_name,
                'customer_phone' => PhoneHelper::toLocal($session->momo_number ?? $validated['momo_number']),
            ]);

            $session->update([
                'status' => 'payment_initiated',
                'hubtel_transaction_id' => $result['transactionId'] ?? null,
                'payment_gateway_response' => $result,
                'last_momo_sent_at' => now(),
            ]);

            return response()->json([
                'session_token' => $session->session_token,
                'status' => 'payment_initiated',
                'transaction_id' => $result['transactionId'] ?? null,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $session->update(['status' => 'failed']);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            // Hubtel unavailable — session stays pending for retry
            Log::warning('Hubtel RMP unavailable for POS checkout session', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'session_token' => $session->session_token,
                'status' => 'pending',
                'message' => 'Mobile money prompt could not be sent. You can retry or switch to cash.',
            ], 201);
        }
    }

    private function posAwaitConfirmFlow(CheckoutSession $session): JsonResponse
    {
        // Cash/Card on POS: session created, frontend opens confirm modal
        return response()->json([
            'session_token' => $session->session_token,
            'status' => 'pending',
            'payment_method' => $session->payment_method,
            'total_amount' => $session->total_amount,
            'is_manual_entry' => (bool) $session->is_manual_entry,
        ], 201);
    }

    private function posInstantFlow(CheckoutSession $session): JsonResponse
    {
        // manual_momo, no_charge, wallet, ghqr → create order immediately
        $order = $this->orderCreationService->createFromCheckoutSession($session);

        return response()->json([
            'session_token' => $session->session_token,
            'status' => 'confirmed',
            'order' => new OrderResource($order),
        ], 201);
    }

    private function resolveCartIdentity(Request $request): array
    {
        $user = Auth::guard('sanctum')->user();
        if ($user?->customer) {
            return ['customer_id' => $user->customer->id, 'session_id' => null];
        }

        return ['customer_id' => null, 'session_id' => $request->attributes->get('guest_session_id')];
    }

    /**
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function verifyStaffAuthorization(\App\Models\Employee $employee, int $branchId): void
    {
        $branch = Branch::find($branchId);
        if (! $branch) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['message' => 'Invalid branch'], 422)
            );
        }

        // Company-wide roles place orders against any branch — the call centre
        // takes a call for Ashaiman and the next one for Spintex, so the branch
        // belongs to the order, not to the agent. See User::isCompanyWide.
        if ($employee->user->isCompanyWide()) {
            return;
        }

        if ($employee->user->hasRole(Role::Manager)) {
            if ($employee->managedBranches()->where('branches.id', $branchId)->exists()) {
                return;
            }
        }

        if (! $employee->branches()->where('branches.id', $branchId)->exists()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['message' => 'You are not authorized to create orders for this branch'], 403)
            );
        }
    }

    private function validateMenuItems(array $items, int $branchId): \Illuminate\Support\Collection
    {
        $menuItemIds = array_column($items, 'menu_item_id');

        $menuItems = MenuItem::whereIn('id', $menuItemIds)
            ->with(['branch', 'options' => fn ($q) => $q->orderBy('display_order')])
            ->get()
            ->keyBy('id');

        foreach ($menuItemIds as $id) {
            if (! $menuItems->has($id)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => 'Invalid menu item'], 422)
                );
            }
        }

        // A dish is one row served at many branches — see
        // MenuItem::firstNotOnSaleAt. This used to compare the legacy
        // `branch_id` column, which after the menu merge refused every branch
        // except the one the dish happened to be consolidated onto.
        if ($offender = MenuItem::firstNotOnSaleAt($menuItemIds, $branchId)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['message' => "Menu item {$offender->name} is not available at this branch"], 422)
            );
        }

        foreach ($items as $item) {
            if (isset($item['menu_item_option_id']) && $item['menu_item_option_id'] !== null) {
                $opt = MenuItemOption::find($item['menu_item_option_id']);
                if (! $opt || $opt->menu_item_id !== $item['menu_item_id']) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json(['message' => 'Invalid menu item option'], 422)
                    );
                }
            }
        }

        return $menuItems;
    }

    private function resolveItemPrices(array $items, \Illuminate\Support\Collection $menuItems): array
    {
        return array_map(function (array $item) use ($menuItems): array {
            $menuItemId = (int) $item['menu_item_id'];
            $menuItem = $menuItems->get($menuItemId);

            if (! empty($item['menu_item_option_id'])) {
                $option = MenuItemOption::find($item['menu_item_option_id']);
                if ($option && $option->menu_item_id === $menuItemId) {
                    $item['unit_price'] = (float) $option->price;

                    return $item;
                }
            }

            if ($menuItem) {
                $first = $menuItem->options->first();
                if ($first) {
                    $item['unit_price'] = (float) $first->price;
                    $item['menu_item_option_id'] = $first->id;
                }
            }

            return $item;
        }, $items);
    }
}
