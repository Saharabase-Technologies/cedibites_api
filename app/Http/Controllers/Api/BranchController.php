<?php

namespace App\Http\Controllers\Api;

use App\Events\BranchAccessUpdatedEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\OrderResource;
use App\Models\Branch;
use App\Models\Order;
use App\Notifications\BranchManagerAssignedNotification;
use App\Notifications\BranchManagerRemovedNotification;
use App\Services\Analytics\AnalyticsService;
use App\Services\BranchProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->with([
                'menuItems.category',
                'menuItems.options',
                'managers.user',
                'operatingHours',
                'deliverySettings',
                'orderTypes',
                'paymentMethods',
            ])
            ->when($request->is_active !== null, fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->area, fn ($query, $area) => $query->where('area', $area))
            ->get();

        // Today's takings are attached only for staff who may see figures for that
        // branch. This endpoint is also served unauthenticated (routes/public.php)
        // so customers can pick a branch, and it was handing every passer-by the
        // day's revenue for every outlet. Menu, hours and address are meant to be
        // public; the money is not.
        $user = $request->user();
        $canSeeFigures = $user?->can('view_analytics') ?? false;
        $isAdmin = $user?->hasAnyRole(['admin', 'tech_admin']) ?? false;
        $assignedBranchIds = $isAdmin
            ? null
            : ($user?->employee?->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all() ?? []);

        $branchesWithStats = $branches->map(function ($branch) use ($canSeeFigures, $assignedBranchIds) {
            $data = (new BranchResource($branch))->toArray(request());

            $maySeeThisBranch = $canSeeFigures
                && ($assignedBranchIds === null || in_array((int) $branch->id, $assignedBranchIds, true));

            if ($maySeeThisBranch) {
                $stats = $this->analyticsService->getBranchTodayStats($branch->id);
                $data['today_orders'] = $stats['orders_today'];
                $data['today_revenue'] = $stats['revenue_today'];
            }

            return $data;
        });

        return response()->success($branchesWithStats);
    }

    /**
     * Get basic branch information without menu items.
     */
    public function basic(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->when($request->is_active !== null, fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->area, fn ($query, $area) => $query->where('area', $area))
            ->select(['id', 'name', 'area', 'address', 'is_active'])
            ->get();

        return response()->success($branches->map(function ($branch) {
            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'area' => $branch->area,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
            ];
        }));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        try {
            \DB::beginTransaction();

            // Create branch
            $branch = Branch::create($request->only([
                'name', 'area', 'address', 'phone', 'email',
                'latitude', 'longitude', 'is_active',
            ]));

            // Assign manager if provided
            if ($request->has('manager_id') && $request->manager_id) {
                $employee = \App\Models\Employee::find($request->manager_id);
                if ($employee) {
                    // Ensure employee has manager role first
                    if (! $employee->user->hasRole('manager')) {
                        $employee->user->assignRole('manager');
                    }

                    // Attach employee to branch
                    $branch->employees()->attach($employee->id);
                }
            }

            // Create operating hours
            if ($request->has('operating_hours')) {
                foreach ($request->operating_hours as $day => $hours) {
                    $branch->operatingHours()->create([
                        'day_of_week' => $day,
                        'is_open' => $hours['is_open'] ?? true,
                        'open_time' => $hours['open_time'] ?? null,
                        'close_time' => $hours['close_time'] ?? null,
                    ]);
                }
            }

            // Create delivery settings
            if ($request->has('delivery_settings')) {
                $branch->deliverySettings()->create(array_merge(
                    $request->delivery_settings,
                    [
                        'is_active' => true,
                        'effective_from' => now(),
                    ]
                ));
            }

            // Create order types
            if ($request->has('order_types')) {
                foreach ($request->order_types as $type => $config) {
                    $branch->orderTypes()->create([
                        'order_type' => $type,
                        'is_enabled' => $config['is_enabled'] ?? true,
                        'metadata' => $config['metadata'] ?? null,
                    ]);
                }
            }

            // Create payment methods
            if ($request->has('payment_methods')) {
                foreach ($request->payment_methods as $method => $config) {
                    $branch->paymentMethods()->create([
                        'payment_method' => $method,
                        'is_enabled' => $config['is_enabled'] ?? true,
                        'metadata' => $config['metadata'] ?? null,
                    ]);
                }
            }

            // Give the branch its inventory location. Without one its manager
            // cannot see stock at all, and its sales silently debit the mother
            // kitchen instead. Inside the transaction so a branch is never
            // created half-provisioned.
            app(BranchProvisioningService::class)->provision($branch);

            \DB::commit();

            return response()->created(
                new BranchResource($branch->load([
                    'operatingHours', 'deliverySettings', 'orderTypes', 'paymentMethods', 'managers.user',
                ]))
            );
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to create branch: '.$e->getMessage());

            return response()->server_error();
        }
    }

    /**
     * Get branch by name.
     */
    public function getByName(string $name): JsonResponse
    {
        $branch = Branch::query()
            ->where('name', $name)
            ->orWhere('area', $name)
            ->first();

        if (! $branch) {
            return response()->success(null);
        }

        return response()->success(new BranchResource($branch));
    }

    /**
     * Get menu item IDs for a branch.
     */
    /**
     * Get menu items for a branch with full details.
     */
    /**
     * Get menu item IDs for a branch.
     */
    public function getMenuItemIds(Branch $branch): JsonResponse
    {
        $ids = $branch->menuItems()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        return response()->success($ids);
    }

    /**
     * Check if a menu item is available at a branch.
     */
    public function isItemAvailable(Branch $branch, string $itemId): JsonResponse
    {
        $available = $branch->menuItems()
            ->where('id', (int) $itemId)
            ->where('is_available', true)
            ->exists();

        return response()->success($available);
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch): JsonResponse
    {
        $branch->load([
            'employees',
            'menuCategories',
            'menuItems',
            'operatingHours',
            'deliverySettings',
            'orderTypes',
            'paymentMethods',
            'managers.user',
        ]);

        return response()->success(new BranchResource($branch));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        try {
            \DB::beginTransaction();

            // Update branch core fields
            $branch->update($request->only([
                'name', 'area', 'address', 'phone', 'email',
                'latitude', 'longitude', 'is_active',
            ]));

            // Update manager if provided
            if ($request->has('manager_id')) {
                // Get all employees with manager role attached to this branch
                $currentManagerIds = $branch->employees()
                    ->whereHas('user.roles', function ($query) {
                        $query->where('name', 'manager');
                    })
                    ->pluck('employees.id');

                // Get current managers for notification
                $currentManagers = $branch->employees()
                    ->whereHas('user.roles', function ($query) {
                        $query->where('name', 'manager');
                    })
                    ->with('user')
                    ->get();

                // Detach all current managers and notify them
                if ($currentManagerIds->isNotEmpty()) {
                    $branch->employees()->detach($currentManagerIds);

                    // Send removal notifications to previous managers
                    foreach ($currentManagers as $manager) {
                        $manager->user->notify(new BranchManagerRemovedNotification($branch));
                    }
                }

                // Assign new manager if provided
                if ($request->manager_id) {
                    $employee = \App\Models\Employee::find($request->manager_id);
                    if ($employee) {
                        // Ensure employee has manager role first
                        if (! $employee->user->hasRole('manager')) {
                            $employee->user->assignRole('manager');
                        }

                        // Attach employee to branch
                        $branch->employees()->syncWithoutDetaching([$employee->id]);

                        // Send assignment notification to new manager
                        $employee->user->notify(new BranchManagerAssignedNotification($branch));
                    }
                }
            }

            // Update operating hours
            if ($request->has('operating_hours')) {
                foreach ($request->operating_hours as $day => $hours) {
                    $branch->operatingHours()->updateOrCreate(
                        ['day_of_week' => $day],
                        [
                            'is_open' => $hours['is_open'] ?? true,
                            'open_time' => $hours['open_time'] ?? null,
                            'close_time' => $hours['close_time'] ?? null,
                        ]
                    );
                }
            }

            // Update delivery settings (create new active one, deactivate old)
            if ($request->has('delivery_settings')) {
                // Deactivate current active settings
                $branch->deliverySettings()->where('is_active', true)->update(['is_active' => false]);

                // Create new active setting
                $branch->deliverySettings()->create(array_merge(
                    $request->delivery_settings,
                    [
                        'is_active' => true,
                        'effective_from' => now(),
                    ]
                ));
            }

            // Update order types
            if ($request->has('order_types')) {
                foreach ($request->order_types as $type => $config) {
                    $branch->orderTypes()->updateOrCreate(
                        ['order_type' => $type],
                        [
                            'is_enabled' => $config['is_enabled'] ?? true,
                            'metadata' => $config['metadata'] ?? null,
                        ]
                    );
                }
            }

            // Update payment methods
            if ($request->has('payment_methods')) {
                foreach ($request->payment_methods as $method => $config) {
                    $branch->paymentMethods()->updateOrCreate(
                        ['payment_method' => $method],
                        [
                            'is_enabled' => $config['is_enabled'] ?? true,
                            'metadata' => $config['metadata'] ?? null,
                        ]
                    );
                }
            }

            \DB::commit();

            return response()->success(
                new BranchResource($branch->fresh()->load([
                    'operatingHours', 'deliverySettings', 'orderTypes', 'paymentMethods', 'managers.user',
                ]))
            );
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to update branch: '.$e->getMessage());

            return response()->server_error();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch): JsonResponse
    {
        try {
            $branch->delete();

            return response()->json(['success' => true, 'message' => 'Branch deleted successfully']);
        } catch (\Exception $e) {
            return response()->server_error();
        }
    }

    /**
     * Get branch employees.
     */
    public function employees(Branch $branch): JsonResponse
    {
        $employees = $branch->employees()
            ->with(['user.roles', 'branches'])
            ->orderBy('employees.created_at', 'desc')
            ->get();

        return response()->success(
            EmployeeResource::collection($employees),
            'Branch employees retrieved successfully.'
        );
    }

    /**
     * Get branch orders.
     */
    public function orders(Request $request, Branch $branch): JsonResponse
    {
        $query = $branch->orders()->paymentConfirmed()->with(['customer.user', 'items.menuItemOption.menuItem', 'payments']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->latest()->paginate($request->per_page ?? 15);

        return response()->success(
            OrderResource::collection($orders)->response()->getData(true),
            'Branch orders retrieved successfully.'
        );
    }

    /**
     * Get branch statistics.
     */
    public function stats(Branch $branch): JsonResponse
    {
        $stats = $this->analyticsService->getBranchDetailStats($branch->id);

        return response()->success($stats, 'Branch statistics retrieved successfully.');
    }

    /**
     * Get top selling items for a branch.
     */
    public function topItems(Request $request, Branch $branch): JsonResponse
    {
        $period = $request->get('date', 'today');
        $limit = (int) $request->get('limit', 5);

        $topItems = $this->analyticsService->getBranchTopItems($branch->id, $period, $limit);

        return response()->success($topItems, 'Top items retrieved successfully.');
    }

    /**
     * Get daily revenue chart data for a branch.
     */
    public function revenueChart(Request $request, Branch $branch): JsonResponse
    {
        $period = $request->get('period', 'week');

        $chartData = $this->analyticsService->getBranchRevenueChart($branch->id, $period);

        return response()->success($chartData, 'Revenue chart data retrieved successfully.');
    }

    /**
     * Toggle the daily open/closed status for a branch.
     */
    public function toggleDailyStatus(Branch $branch): JsonResponse
    {
        try {
            $today = strtolower(now()->format('l')); // monday, tuesday, etc.

            $operatingHour = $branch->operatingHours()
                ->where('day_of_week', $today)
                ->first();

            if (! $operatingHour) {
                return response()->error('No operating hours found for today', 404);
            }

            $currentlyOpen = $operatingHour->isCurrentlyOpen();

            // Set manual override to opposite of current status
            $operatingHour->update([
                'manual_override_open' => ! $currentlyOpen,
                'manual_override_at' => now(),
            ]);

            $status = $operatingHour->manual_override_open ? 'opened' : 'closed';

            return response()->success([
                'message' => "Branch manually {$status} successfully",
                'is_open' => $operatingHour->manual_override_open,
                'is_manual_override' => true,
                'day' => $today,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to toggle branch daily status: '.$e->getMessage());

            return response()->server_error();
        }
    }

    /**
     * Clear manual override and return to scheduled hours.
     */
    public function clearManualOverride(Branch $branch): JsonResponse
    {
        try {
            $today = strtolower(now()->format('l'));

            $operatingHour = $branch->operatingHours()
                ->where('day_of_week', $today)
                ->first();

            if (! $operatingHour) {
                return response()->error('No operating hours found for today', 404);
            }

            $operatingHour->update([
                'manual_override_open' => null,
                'manual_override_at' => null,
            ]);

            $followsSchedule = $operatingHour->fresh()->isCurrentlyOpen();

            return response()->success([
                'message' => 'Manual override cleared - now following scheduled hours',
                'is_open' => $followsSchedule,
                'is_manual_override' => false,
                'day' => $today,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to clear manual override: '.$e->getMessage());

            return response()->server_error();
        }
    }

    /**
     * Get per-staff daily sales breakdown by payment method.
     */
    public function staffSales(Request $request, Branch $branch): JsonResponse
    {
        $date = $request->input('date', now()->toDateString());

        $rows = $this->analyticsService->getStaffSalesMetrics([
            'branch_id' => $branch->id,
            'date_from' => $date,
            'date_to' => $date,
        ]);

        return response()->success($rows);
    }

    /**
     * Toggle the extended staff access flag for a branch.
     * When enabled, staff can access POS, KDS, and Order Manager beyond closing hours.
     */
    public function toggleExtendedStaffAccess(Branch $branch): JsonResponse
    {
        $branch->update([
            'extended_staff_access' => ! $branch->extended_staff_access,
        ]);

        // If staff access is being disabled, also disable order access
        if (! $branch->extended_staff_access) {
            $branch->update(['extended_order_access' => false]);
        }

        BranchAccessUpdatedEvent::dispatch($branch->fresh());

        $status = $branch->extended_staff_access ? 'enabled' : 'disabled';

        return response()->success([
            'extended_staff_access' => $branch->extended_staff_access,
            'extended_order_access' => $branch->extended_order_access,
        ], "Extended staff access {$status} for {$branch->name}");
    }

    /**
     * Toggle the extended order access flag for a branch.
     * When enabled (alongside extended_staff_access), staff can place new orders via POS beyond closing hours.
     */
    public function toggleExtendedOrderAccess(Branch $branch): JsonResponse
    {
        if (! $branch->extended_staff_access) {
            return response()->error('Extended staff access must be enabled before enabling order access.', 422);
        }

        $branch->update([
            'extended_order_access' => ! $branch->extended_order_access,
        ]);

        BranchAccessUpdatedEvent::dispatch($branch->fresh());

        $status = $branch->extended_order_access ? 'enabled' : 'disabled';

        return response()->success([
            'extended_staff_access' => $branch->extended_staff_access,
            'extended_order_access' => $branch->extended_order_access,
        ], "Extended order access {$status} for {$branch->name}");
    }
}
