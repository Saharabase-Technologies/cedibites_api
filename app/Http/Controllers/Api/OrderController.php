<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderFromCartRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\OrderNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    private function taxRate(): float
    {
        return (float) config('app.tax_rate', 0.20);
    }

    /**
     * Whether this user is allowed to see or touch this particular order.
     *
     * An order belongs to two people: the customer who placed it, and the branch
     * that has to make it. Everyone else is a stranger to it — including staff at
     * another branch, who have no reason to read a stranger's name, phone and
     * delivery address.
     *
     * Admins sit above the branch structure and see everything.
     */
    private function canAccessOrder(?User $user, Order $order): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            return true;
        }

        // The customer who placed it.
        if ($user->customer && (int) $order->customer_id === (int) $user->customer->id) {
            return true;
        }

        // Staff at the branch that owns it.
        return $user->employee
            ?->branches()
            ->where('branches.id', $order->branch_id)
            ->exists() ?? false;
    }

    /**
     * Normalise a Ghana phone number to +233XXXXXXXXX format so that
     * "0539157613" and "+233539157613" resolve to the same user record.
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (str_starts_with($phone, '0')) {
            return '+233'.substr($phone, 1);
        }
        if (str_starts_with($phone, '233') && ! str_starts_with($phone, '+')) {
            return '+'.$phone;
        }

        return $phone;
    }

    /**
     * Resolve cart identity (same logic as CartController).
     *
     * @return array{customer_id: int|null, session_id: string|null}
     */
    private function resolveCartIdentity(Request $request): array
    {
        $user = Auth::guard('sanctum')->user();
        if ($user?->customer) {
            return ['customer_id' => $user->customer->id, 'session_id' => null];
        }

        return ['customer_id' => null, 'session_id' => $request->attributes->get('guest_session_id')];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->customer) {
            return response()->error('Unauthenticated.', 401);
        }

        $orders = Order::with(['customer.user', 'branch', 'items.menuItem'])
            ->where('customer_id', $user->customer->id)
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->paginated(new OrderCollection($orders));
    }

    /**
     * Store a new order from cart (frontend format; auth or guest via cart.identity).
     *
     * @deprecated Use POST /checkout-sessions instead. This endpoint will be removed in a future release.
     */
    public function store(StoreOrderFromCartRequest $request): JsonResponse
    {
        // Deprecation header — clients should migrate to checkout-sessions flow
        header('Deprecation: true');
        header('Sunset: 2025-09-01');
        header('Link: </checkout-sessions>; rel="successor-version"');

        $identity = $this->resolveCartIdentity($request);

        // For guest orders, find or create a customer record by phone so guest
        // customers appear in the admin customers list.
        $resolvedCustomerId = $identity['customer_id'];
        if ($resolvedCustomerId === null) {
            $validated = $request->validated();
            $guestPhone = $validated['customer_phone'] ?? null;
            if ($guestPhone) {
                $normalizedPhone = $this->normalizePhone($guestPhone);
                $guestName = $validated['customer_name'] ?? 'Customer';
                $guestUser = User::firstOrCreate(
                    ['phone' => $normalizedPhone],
                    ['name' => $guestName]
                );

                // Keep the display name current when the same phone is used with a different name
                if ($guestName !== 'Customer' && $guestUser->name !== $guestName && ! $guestUser->wasRecentlyCreated) {
                    $guestUser->update(['name' => $guestName]);
                }

                if (! $guestUser->customer) {
                    $guestUser->customer()->create(['is_guest' => true]);
                    $guestUser->load('customer');
                }
                $resolvedCustomerId = $guestUser->customer->id;
            }
        }

        $cartQuery = Cart::with(['items.menuItem', 'items.menuItemOption', 'branch'])
            ->where('status', 'active');

        if ($identity['customer_id'] !== null) {
            $cartQuery->where('customer_id', $identity['customer_id']);
        } else {
            $cartQuery->whereNull('customer_id')->where('session_id', $identity['session_id']);
        }

        $cart = $cartQuery->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->error('Cart is empty. Add items before placing an order.', 422);
        }

        $validated = $request->validated();

        if ((int) $validated['branch_id'] !== (int) $cart->branch_id) {
            return response()->error('Cart branch does not match selected branch.', 422);
        }
        $branch = $cart->branch;
        $subtotal = $cart->items->sum('subtotal');
        $deliveryFee = 0; // Delivery fees temporarily disabled
        $taxAmount = round($subtotal * ($this->taxRate() / (1 + $this->taxRate())), 2);
        $totalAmount = $subtotal + $deliveryFee;

        $orderNumber = (new OrderNumberService)->generate();

        try {
            DB::beginTransaction();

            $order = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $resolvedCustomerId,
                'branch_id' => (int) $validated['branch_id'],
                'order_type' => $validated['order_type'],
                'order_source' => 'online',
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_latitude' => $validated['delivery_latitude'] ?? null,
                'delivery_longitude' => $validated['delivery_longitude'] ?? null,
                'contact_name' => $validated['customer_name'],
                'contact_phone' => $validated['customer_phone'],
                'delivery_note' => $validated['special_instructions'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'tax_rate' => $this->taxRate(),
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'status' => 'received',
            ]);

            foreach ($cart->items as $cartItem) {
                $mi = $cartItem->menuItem;
                $opt = $cartItem->menuItemOption;

                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $cartItem->menu_item_id,
                    'menu_item_option_id' => $cartItem->menu_item_option_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'subtotal' => $cartItem->subtotal,
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
                ]);
            }

            $paymentMethod = $validated['payment_method'];
            // Mobile money goes through Hubtel — create as pending so initiateHubtelPayment
            // can proceed. All other methods (cash_on_delivery, card, etc.) are completed
            // immediately since no gateway confirmation is needed.
            $isMomo = $paymentMethod === 'mobile_money';

            // Goods amount only — delivery fee is collected by the rider on delivery.
            Payment::create([
                'order_id' => $order->id,
                'customer_id' => $resolvedCustomerId,
                'payment_method' => $paymentMethod,
                'payment_status' => $isMomo ? 'pending' : 'completed',
                'amount' => $order->goods_amount,
                'paid_at' => $isMomo ? null : now(),
            ]);

            $cart->update(['status' => 'completed']);

            DB::commit();

            $order->load(['customer.user', 'branch', 'items.menuItem', 'payments']);

            return response()->created(new OrderResource($order));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation failed', ['error' => $e->getMessage(), 'exception' => $e]);

            return response()->server_error();
        }
    }

    /**
     * Display order by order number (public, for guest tracking).
     * Returns a minimal response without PII for unauthenticated callers.
     */
    public function showByNumber(string $orderNumber): JsonResponse
    {
        $order = Order::with(['branch', 'items.menuItem', 'items.menuItemOption.media', 'statusHistory', 'payments'])
            ->where('order_number', $orderNumber)
            ->first();

        if (! $order) {
            return response()->error('Order not found.', 404);
        }

        return response()->success([
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'order_type' => $order->order_type,
            'total_amount' => (float) $order->total_amount,
            'branch' => [
                'name' => $order->branch?->name ?? '—',
            ],
            'items' => $order->items->map(fn ($item) => [
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'menu_item' => [
                    'name' => $item->menuItem?->name,
                ],
            ]),
            'status_history' => $order->statusHistory->map(fn ($history) => [
                'status' => $history->status,
                'changed_at' => $history->changed_at?->toIso8601String(),
            ]),
            'created_at' => $order->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Order manager feed. Authenticated employees see only their branches; optional branch_id must be allowed.
     * Unauthenticated callers may filter by branch_id only (display boards).
     */
    public function orderManagerOrders(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');

        $query = Order::with(['branch', 'customer.user', 'items.menuItem', 'items.menuItemOption.media', 'statusHistory', 'payments'])
            ->paymentConfirmed()
            ->whereIn('status', ['received', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc');

        $user = Auth::guard('sanctum')->user();
        $employee = $user?->employee;

        if ($employee && ! $user->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            $allowed = $employee->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $allowed);
            if ($branchId !== null && $branchId !== '') {
                $bid = (int) $branchId;
                if (! $allowed->contains($bid)) {
                    return response()->json(['message' => 'You cannot view orders for this branch.'], 403);
                }
                $query->where('branch_id', $bid);
            }
        } elseif ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', (int) $branchId);
        }

        return response()->success(OrderResource::collection($query->get()));
    }

    /**
     * Kitchen display feed. Orders in kitchen-relevant statuses: received,
     * accepted, preparing, ready.
     *
     * Requires `access_kitchen` (see routes/protected.php). Authenticated
     * employees see only their own branches, and an out-of-scope `branch_id`
     * is refused rather than quietly honoured — a kitchen has no business
     * watching another branch's tickets. Mirrors orderManagerOrders() above.
     */
    public function kitchenOrders(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');

        $query = Order::with(['branch', 'items.menuItem', 'items.menuItemOption.media'])
            ->paymentConfirmed()
            ->whereIn('status', ['received', 'accepted', 'preparing', 'ready'])
            ->orderBy('created_at', 'asc');

        $user = $request->user();
        $employee = $user?->employee;

        if ($employee && ! $user->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            $allowed = $employee->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $allowed);

            if ($branchId !== null && $branchId !== '') {
                $bid = (int) $branchId;
                if (! $allowed->contains($bid)) {
                    return response()->json(['message' => 'You cannot view orders for this branch.'], 403);
                }
                $query->where('branch_id', $bid);
            }
        } elseif ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', (int) $branchId);
        }

        return response()->success(OrderResource::collection($query->get()));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        if (! $this->canAccessOrder($request->user(), $order)) {
            return response()->error('Order not found.', 404);
        }

        $order->load(['customer.user', 'branch', 'items.menuItem', 'statusHistory', 'payments']);

        return response()->success(new OrderResource($order));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        if (! $this->canAccessOrder($request->user(), $order)) {
            return response()->error('You can only update orders from your branch.', 403);
        }

        try {
            $order->update($request->validated());

            return response()->success(
                new OrderResource($order->fresh(['customer.user', 'branch', 'items']))
            );
        } catch (\Exception $e) {
            Log::error('Order update failed', ['order_id' => $order->id, 'error' => $e->getMessage(), 'exception' => $e]);

            return response()->server_error();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        if (! $this->canAccessOrder($request->user(), $order)) {
            return response()->error('You can only delete orders from your branch.', 403);
        }

        try {
            $order->delete();

            return response()->deleted();
        } catch (\Exception $e) {
            Log::error('Order deletion failed', ['order_id' => $order->id, 'error' => $e->getMessage(), 'exception' => $e]);

            return response()->server_error();
        }
    }

    /**
     * Cancel an order.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->status === 'cancelled') {
            return response()->error('Order is already cancelled', 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $order->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_reason' => $validated['reason'] ?? null,
        ]);

        // Send SMS + notification to customer
        try {
            $notifiable = $order->customer?->user;
            if (! $notifiable && $order->contact_phone) {
                // Guest / walk-in — send directly via SMS channel using a plain notifiable
                $notifiable = new \Illuminate\Notifications\AnonymousNotifiable;
                $notifiable->route('sms', $order->contact_phone);
            }
            if ($notifiable) {
                $notifiable->notify(new \App\Notifications\OrderCancelledNotification($order));
            }
        } catch (\Exception $e) {
            Log::warning('Failed to send cancellation notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($order)
            ->withProperties(['reason' => $validated['reason'] ?? null])
            ->log("Order {$order->order_number} cancelled");

        return response()->success(
            new OrderResource($order->fresh(['customer.user', 'branch', 'items', 'payments']))
        );
    }
}
