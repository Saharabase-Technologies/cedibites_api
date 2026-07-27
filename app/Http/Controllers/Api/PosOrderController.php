<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePosOrderRequest;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Services\HubtelPaymentService;
use App\Services\OrderNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PosOrderController extends Controller
{
    private function taxRate(): float
    {
        return (float) config('app.tax_rate', 0.20);
    }

    private const PRICE_TOLERANCE = 0.01;

    /**
     * Normalise a Ghana phone number to +233XXXXXXXXX so that
     * "0539157613" and "+233539157613" map to the same customer record.
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
     * @deprecated Use POST /pos/checkout-sessions instead. This endpoint will be removed in a future release.
     */
    public function store(StorePosOrderRequest $request): JsonResponse
    {
        // Deprecation header — clients should migrate to checkout-sessions flow
        header('Deprecation: true');
        header('Sunset: 2025-09-01');
        header('Link: </pos/checkout-sessions>; rel="successor-version"');

        // Log incoming request for debugging
        \Log::info('POS order request received', [
            'validated' => $request->validated(),
            'items' => $request->validated('items'),
        ]);

        // Get authenticated user and retrieve employee record
        $user = $request->user();
        $employee = $user->employee;

        // Verify employee exists and is active
        if (! $employee) {
            return response()->json([
                'message' => 'Employee record not found',
            ], 403);
        }

        if ($employee->status !== \App\Enums\EmployeeStatus::Active) {
            return response()->json([
                'message' => 'Your account is not active',
            ], 403);
        }

        // Verify branch and staff authorization
        $branchId = $request->validated('branch_id');
        $this->verifyStaffAuthorization($employee, $branchId);

        // Validate menu items
        $items = $request->validated('items');
        $menuItems = $this->validateMenuItems($items, $branchId);

        // Resolve authoritative DB prices for every item
        $items = $this->resolveItemPrices($items, $menuItems);

        try {
            // Wrap all database operations in a transaction
            $order = \DB::transaction(function () use ($request, $employee, $branchId, $items, $menuItems) {
                // Calculate order totals using DB-authoritative prices
                $discount = (float) ($request->validated('discount') ?? 0.0);
                $totals = $this->calculateOrderTotals($items, $discount);

                // Generate unique order number
                $orderNumber = $this->generateOrderNumber();

                // TODO: store actual fulfillment_type once the DB enum is extended (dine_in/takeaway migration pending)
                $fulfillmentType = 'pickup'; // temporarily hardcoded until migration runs on beta
                $deliveryNote = $request->validated('customer_notes') ?: null;

                $isManualEntry = (bool) ($request->validated('is_manual_entry') ?? false);

                // Find or create a customer record by phone so POS customers
                // appear in the admin customers list.
                $contactPhone = $this->normalizePhone($request->validated('contact_phone') ?? '');
                $contactName = $request->validated('contact_name');

                $posUser = \App\Models\User::firstOrCreate(
                    ['phone' => $contactPhone],
                    ['name' => $contactName]
                );

                // Keep the display name current when the same phone is used with a different name
                if ($contactName && $posUser->name !== $contactName && ! $posUser->wasRecentlyCreated) {
                    $posUser->update(['name' => $contactName]);
                }

                if (! $posUser->customer) {
                    $posUser->customer()->create(['is_guest' => true]);
                    $posUser->load('customer');
                }

                $posCustomerId = $posUser->customer->id;

                // Create order record
                $order = \App\Models\Order::create([
                    'order_number' => $orderNumber,
                    'branch_id' => $branchId,
                    'assigned_employee_id' => $employee->id,
                    'order_type' => $fulfillmentType,
                    'order_source' => $isManualEntry ? 'manual_entry' : 'pos',
                    'contact_name' => $contactName,
                    'contact_phone' => $contactPhone,
                    'delivery_note' => $deliveryNote,
                    'subtotal' => $totals['subtotal'],
                    'delivery_fee' => $totals['delivery_fee'],
                    'tax_rate' => $this->taxRate(),
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['total_amount'],
                    'status' => $isManualEntry ? 'completed' : 'received',
                    'customer_id' => $posCustomerId,
                    'delivery_address' => null,
                    'delivery_latitude' => null,
                    'delivery_longitude' => null,
                    'recorded_at' => $isManualEntry ? $request->validated('recorded_at') : null,
                ]);

                // Create order items
                foreach ($items as $item) {
                    $menuItem = $menuItems->get($item['menu_item_id']);
                    $menuItemOption = null;

                    if (isset($item['menu_item_option_id']) && $item['menu_item_option_id'] !== null) {
                        $menuItemOption = MenuItemOption::find($item['menu_item_option_id']);
                    }

                    $snapshots = $this->createOrderSnapshots($menuItem, $menuItemOption);

                    \App\Models\OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $item['menu_item_id'],
                        'menu_item_option_id' => $item['menu_item_option_id'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['quantity'] * $item['unit_price'],
                        'menu_item_snapshot' => $snapshots['menu_item_snapshot'],
                        'menu_item_option_snapshot' => $snapshots['menu_item_option_snapshot'],
                    ]);
                }

                // Get branch for logging and payment description
                $branch = \App\Models\Branch::find($branchId);

                // Create payment record based on payment method
                $paymentMethod = $request->validated('payment_method');

                if ($paymentMethod === 'mobile_money') {
                    // For mobile money, use Hubtel Direct Receive Money (RMP)
                    // This sends a USSD prompt directly to the customer's phone
                    try {
                        $hubtelService = app(HubtelPaymentService::class);

                        $momoPhone = $request->validated('momo_number');

                        $hubtelService->initializeReceiveMoney([
                            'order' => $order,
                            'description' => "POS Order {$orderNumber} - {$branch->name}",
                            'customer_name' => $request->validated('contact_name'),
                            'customer_phone' => $momoPhone,
                        ]);

                        // Payment record is created by initializeReceiveMoney() with status 'pending'
                        // The payment will be completed via RMP callback when customer approves
                    } catch (\InvalidArgumentException $e) {
                        // Phone prefix not recognised as a valid Ghana MoMo number
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json(['message' => $e->getMessage()], 422)
                        );
                    } catch (\Exception $e) {
                        // Hubtel RMP not configured or API failure — create pending payment for manual handling
                        \Log::warning('Hubtel RMP unavailable, creating pending payment', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);

                        \App\Models\Payment::create([
                            'order_id' => $order->id,
                            'payment_method' => $paymentMethod,
                            'amount' => $order->goods_amount,
                            'payment_status' => 'pending',
                            'customer_id' => $posCustomerId,
                        ]);
                    }
                } elseif ($paymentMethod === 'no_charge') {
                    \App\Models\Payment::create([
                        'order_id' => $order->id,
                        'payment_method' => 'no_charge',
                        'amount' => 0,
                        'payment_status' => 'no_charge',
                        'customer_id' => $posCustomerId,
                    ]);
                } elseif ($paymentMethod === 'manual_momo') {
                    // Manual MoMo — direct transfer to branch, no gateway
                    \App\Models\Payment::create([
                        'order_id' => $order->id,
                        'payment_method' => 'manual_momo',
                        'amount' => $order->goods_amount,
                        'payment_status' => 'completed',
                        'paid_at' => $isManualEntry ? $request->validated('recorded_at') : now(),
                        'customer_id' => $posCustomerId,
                        'transaction_id' => $request->validated('momo_reference'),
                    ]);
                } else {
                    // For cash, card, wallet, ghqr - mark as completed immediately
                    \App\Models\Payment::create([
                        'order_id' => $order->id,
                        'payment_method' => $paymentMethod,
                        'amount' => $order->goods_amount,
                        'payment_status' => 'completed',
                        'paid_at' => $isManualEntry ? $request->validated('recorded_at') : now(),
                        'customer_id' => $posCustomerId,
                    ]);
                }

                // Log activity
                $entryType = $isManualEntry ? 'Manual entry' : 'POS order';
                $description = "{$entryType} {$orderNumber} created by {$employee->user->name} at {$branch->name} for GHS {$totals['total_amount']}";

                activity()
                    ->causedBy($request->user())
                    ->performedOn($order)
                    ->withProperties([
                        'order_number' => $orderNumber,
                        'branch_name' => $branch->name,
                        'staff_name' => $employee->user->name,
                        'total_amount' => $totals['total_amount'],
                        'is_manual_entry' => $isManualEntry,
                    ])
                    ->log($description);

                return $order;
            });

            // Load relationships for response
            $order->load(['branch', 'assignedEmployee.user', 'items.menuItem', 'items.menuItemOption.media', 'payments']);
            $order->makeHidden(['tax_rate', 'tax_amount']);

            // For mobile money, include payment ID so the frontend can poll for status
            $responseData = ['data' => $order];

            if ($request->validated('payment_method') === 'mobile_money') {
                $payment = $order->payments->first();
                if ($payment) {
                    $responseData['payment'] = [
                        'id' => $payment->id,
                        'status' => $payment->payment_status,
                        'transaction_id' => $payment->transaction_id,
                    ];
                }
            }

            // Return response with 201 status
            return response()->json($responseData, 201);

        } catch (\Exception $e) {
            // Log error for debugging
            \Log::error('POS order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'employee_id' => $employee->id,
                'branch_id' => $branchId,
            ]);

            // Return 500 error response
            return response()->json([
                'message' => 'An error occurred while creating the order. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify that the branch exists and the employee is authorized to create orders for it.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function verifyStaffAuthorization(\App\Models\Employee $employee, int $branchId): void
    {
        // Verify branch exists
        $branch = \App\Models\Branch::find($branchId);
        if (! $branch) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => 'Invalid branch',
                ], 422)
            );
        }

        // Admins and super admins can create orders for any branch
        if ($employee->user->hasAnyRole([\App\Enums\Role::Admin, \App\Enums\Role::TechAdmin])) {
            return;
        }

        // Call center agents can create orders for any branch
        if ($employee->user->hasRole(\App\Enums\Role::CallCenter)) {
            return;
        }

        // Branch managers can create orders for branches they manage
        if ($employee->user->hasRole(\App\Enums\Role::Manager)) {
            $managesBranch = $employee->managedBranches()->where('branches.id', $branchId)->exists();
            if ($managesBranch) {
                return;
            }
        }

        // Verify employee is assigned to the branch
        $isAssignedToBranch = $employee->branches()->where('branches.id', $branchId)->exists();
        if (! $isAssignedToBranch) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => 'You are not authorized to create orders for this branch',
                ], 403)
            );
        }
    }

    /**
     * Validate that all menu items exist, belong to the branch, and verify sizes if provided.
     *
     * @param  array  $items  The items array from the request
     * @param  int  $branchId  The branch ID to validate against
     * @return \Illuminate\Support\Collection<int, \App\Models\MenuItem>
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function validateMenuItems(array $items, int $branchId): Collection
    {
        // Extract all menu_item_id values
        $menuItemIds = array_column($items, 'menu_item_id');

        // Fetch all menu items at once
        $menuItems = MenuItem::whereIn('id', $menuItemIds)
            ->with([
                'branch',
                'options' => fn ($q) => $q->orderBy('display_order'),
            ])
            ->get()
            ->keyBy('id');

        // Verify all menu items exist
        foreach ($menuItemIds as $menuItemId) {
            if (! $menuItems->has($menuItemId)) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => 'Invalid menu item',
                    ], 422)
                );
            }
        }

        // Verify all menu items belong to the specified branch
        foreach ($menuItems as $menuItem) {
            if ($menuItem->branch_id !== $branchId) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => "Menu item {$menuItem->name} is not available at this branch",
                    ], 422)
                );
            }
        }

        foreach ($items as $item) {
            if (isset($item['menu_item_option_id']) && $item['menu_item_option_id'] !== null) {
                $optionId = $item['menu_item_option_id'];
                $menuItemId = $item['menu_item_id'];

                $option = MenuItemOption::find($optionId);

                if (! $option) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json([
                            'message' => 'Invalid menu item option',
                        ], 422)
                    );
                }

                if ($option->menu_item_id !== $menuItemId) {
                    $menuItem = $menuItems->get($menuItemId);
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json([
                            'message' => "The selected option does not belong to menu item {$menuItem->name}",
                        ], 422)
                    );
                }
            }
        }

        return $menuItems;
    }

    /**
     * Resolve the authoritative DB price for each item, overriding whatever
     * the frontend sent. The backend is always the source of truth for pricing.
     *
     * @param  array  $items  The items array from the request
     * @param  array  $menuItems  MenuItem arrays keyed by ID (from validateMenuItems)
     * @return array Items with unit_price set to the DB-authoritative value
     */
    private function resolveItemPrices(array $items, Collection $menuItems): array
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

    /**
     * Calculate order totals including subtotal, tax, delivery fee, and total amount.
     *
     * @param  array  $items  The items array from the request
     * @param  float  $discount  The discount amount to apply
     * @return array Associative array with subtotal, tax_amount, delivery_fee, total_amount
     */
    private function calculateOrderTotals(array $items, float $discount): array
    {
        // Calculate subtotal by summing (quantity * unit_price) for all items
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += $item['quantity'] * $item['unit_price'];
        }

        // Subtract discount from subtotal if discount > 0
        $subtotalAfterDiscount = $subtotal - $discount;

        // Back-calculate the tax already included in the tax-inclusive prices
        $taxAmount = round($subtotalAfterDiscount * ($this->taxRate() / (1 + $this->taxRate())), 2);

        // Set delivery_fee to 0.00 for POS orders
        $deliveryFee = 0.00;

        // Total is the tax-inclusive subtotal — tax is not added again
        $totalAmount = $subtotalAfterDiscount + $deliveryFee;

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * @return string Unique order number (A001-style series)
     */
    private function generateOrderNumber(): string
    {
        return (new OrderNumberService)->generate();
    }

    /**
     * @return array{menu_item_snapshot: array<string, mixed>, menu_item_option_snapshot: array<string, mixed>|null}
     */
    private function createOrderSnapshots(MenuItem $menuItem, ?MenuItemOption $option): array
    {
        $menuItemSnapshot = [
            'id' => $menuItem->id,
            'name' => $menuItem->name,
            'description' => $menuItem->description,
        ];

        $menuItemOptionSnapshot = null;
        if ($option !== null) {
            $menuItemOptionSnapshot = [
                'id' => $option->id,
                'option_key' => $option->option_key,
                'option_label' => $option->option_label,
                'display_name' => $option->display_name,
                'price' => (float) $option->price,
                'image_url' => $option->getFirstMediaUrl('menu-item-options') ?: null,
            ];
        }

        return [
            'menu_item_snapshot' => $menuItemSnapshot,
            'menu_item_option_snapshot' => $menuItemOptionSnapshot,
        ];
    }

    /**
     * Verify a Ghana mobile money number via the Hubtel Verification API.
     * Returns the registered account name, status, and profile type.
     */
    public function verifyMomo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'momo_number' => ['required', 'string', 'regex:/^(0[0-9]{9}|\+?233[0-9]{9})$/'],
        ], [
            'momo_number.regex' => 'Mobile money number must be a valid Ghana phone number (e.g. 0241234567 or 233241234567)',
        ]);

        try {
            $result = app(HubtelPaymentService::class)->verifyMomoNumber($validated['momo_number']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
