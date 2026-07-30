<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderToShiftRequest;
use App\Http\Requests\StartShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    /**
     * Get all shifts, optionally filtered.
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->forbidden('User is not an employee');
        }

        $query = Shift::query()
            ->with(['employee.user', 'branch', 'shiftOrders.order'])
            ->when($request->employee_id, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->date, fn ($q, $date) => $q->whereDate('login_at', $date))
            ->orderByDesc('login_at');

        if ($request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            // Admins see all shifts
        } elseif ($request->user()?->hasRole(Role::Manager)) {
            // Managers see shifts for their assigned branches
            $branchIds = $employee->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $branchIds);
        } else {
            $query->where('employee_id', $employee->id);
        }

        $shifts = $query->get();

        return response()->success(ShiftResource::collection($shifts));
    }

    /**
     * Get active shift for an employee.
     */
    public function getActive(Request $request, string $employeeId): JsonResponse
    {
        $currentEmployee = $request->user()?->employee;
        if (! $currentEmployee) {
            return response()->forbidden('User is not an employee');
        }

        if ((string) $currentEmployee->id !== $employeeId && ! $request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            return response()->forbidden('Cannot view another employee\'s active shift');
        }

        $shift = Shift::query()
            ->with(['employee.user', 'branch', 'shiftOrders.order'])
            ->where('employee_id', $employeeId)
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();

        if (! $shift) {
            return response()->success(null);
        }

        return response()->success(new ShiftResource($shift));
    }

    /**
     * Start a new shift.
     */
    public function startShift(StartShiftRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        Shift::query()
            ->where('employee_id', $employee->id)
            ->whereNull('logout_at')
            ->update(['logout_at' => now()]);

        $shift = Shift::create([
            'employee_id' => $employee->id,
            'branch_id' => $request->branch_id,
            'login_at' => now(),
        ]);

        $shift->load(['employee.user', 'branch']);

        // A call-centre shift has no branch — they work across all of them.
        $where = $shift->branch?->name ?? 'the call centre';

        activity('shifts')
            ->causedBy($request->user())
            ->performedOn($shift)
            ->event('shift_started')
            ->withProperties(['branch' => $shift->branch?->name])
            ->log("Shift started: {$request->user()->name} at {$where}");

        return response()->created(new ShiftResource($shift));
    }

    /**
     * End a shift.
     */
    public function endShift(Request $request, Shift $shift): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->forbidden('User is not an employee');
        }

        if ($shift->employee_id !== $employee->id && ! $request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            return response()->forbidden('Cannot end another employee\'s shift');
        }

        if ($shift->logout_at) {
            return response()->error('Shift is already ended', 422);
        }

        $shift->update(['logout_at' => now()]);
        $shift->load(['employee.user', 'branch']);

        activity('shifts')
            ->causedBy($request->user())
            ->performedOn($shift)
            ->event('shift_ended')
            ->withProperties([
                'branch' => $shift->branch?->name,
                'total_sales' => $shift->total_sales,
                'order_count' => $shift->order_count,
            ])
            ->log("Shift ended: {$request->user()->name} at ".($shift->branch?->name ?? 'the call centre'));

        return response()->success(new ShiftResource($shift));
    }

    /**
     * Add an order to a shift.
     */
    public function addOrder(AddOrderToShiftRequest $request, Shift $shift): JsonResponse
    {
        $employee = $request->user()->employee;

        if ($shift->employee_id !== $employee->id && ! $request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            return response()->forbidden('Cannot add order to another employee\'s shift');
        }

        if ($shift->logout_at) {
            return response()->error('Cannot add order to ended shift', 422);
        }

        // Try to find order by order_number first, then by ID if numeric
        $order = Order::where('order_number', $request->order_id)->first();

        if (! $order && is_numeric($request->order_id)) {
            $order = Order::find($request->order_id);
        }

        if (! $order) {
            return response()->error('Order not found', 404);
        }

        $orderTotal = (float) $request->order_total;
        $shiftOrder = ShiftOrder::firstOrCreate(
            [
                'shift_id' => $shift->id,
                'order_id' => $order->id,
            ],
            ['order_total' => $orderTotal]
        );

        if ($shiftOrder->wasRecentlyCreated) {
            $shift->increment('total_sales', $orderTotal);
            $shift->increment('order_count');
        }

        return response()->success(null, 'Order added to shift');
    }

    /**
     * Get shifts by date (YYYY-MM-DD).
     */
    public function getByDate(Request $request, string $date): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->forbidden('User is not an employee');
        }

        $query = Shift::query()
            ->with(['employee.user', 'branch', 'shiftOrders.order'])
            ->whereDate('login_at', $date)
            ->orderByDesc('login_at');

        if ($request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
            // Admins see all shifts
        } elseif ($request->user()?->hasRole(Role::Manager)) {
            // Managers see shifts for their assigned branches
            $branchIds = $employee->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $branchIds);
        } else {
            // Regular staff only see their own shifts
            $query->where('employee_id', $employee->id);
        }

        $shifts = $query->get();

        return response()->success(ShiftResource::collection($shifts));
    }

    /**
     * Get shifts by staff ID.
     */
    public function getByStaff(Request $request, string $staffId): JsonResponse
    {
        $employee = $request->user()?->employee;
        if (! $employee) {
            return response()->forbidden('User is not an employee');
        }

        $isAdminOrTech = $request->user()?->hasAnyRole([Role::Admin, Role::TechAdmin]);
        $isManager = $request->user()?->hasRole(Role::Manager);

        if ((string) $employee->id !== $staffId && ! $isAdminOrTech && ! $isManager) {
            return response()->forbidden('Cannot view another employee\'s shifts');
        }

        $query = Shift::query()
            ->with(['employee.user', 'branch', 'shiftOrders.order'])
            ->where('employee_id', $staffId)
            ->orderByDesc('login_at');

        // If manager, scope to their branch(es)
        if ($isManager && ! $isAdminOrTech) {
            $branchIds = $employee->branches()->pluck('branches.id');
            $query->whereIn('branch_id', $branchIds);
        }

        $shifts = $query->get();

        return response()->success(ShiftResource::collection($shifts));
    }
}
