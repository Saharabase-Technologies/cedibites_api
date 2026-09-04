<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftOrder;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Database\Eloquent\Builder;

class OrderManagementService
{
    public function __construct(
        protected AnalyticsService $analyticsService,
    ) {}

    /**
     * Get orders for employee's branch (or all branches for admin/tech_admin).
     */
    public function getBranchOrders(User $user, array $filters = []): Builder
    {
        $employee = $user->employee;
        // A company-wide role is not confined to a branch — the call centre
        // takes orders for every branch and holds no branch assignment at all,
        // which the branch scoping below would otherwise read as "no branches,
        // therefore no orders". See User::isCompanyWide.
        $canSeeAllOrders = $user->isCompanyWide();

        // No payment filter here - admin sees all orders by default
        // `items.menuItemOption.media` is not decoration: OrderResource reads
        // each option's media for its image, and without it that was thirteen
        // extra queries on a board of eighteen orders — one per option, every
        // time the board refreshed.
        $query = Order::with(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItemOption.media', 'items.menuItem.category', 'payments', 'branch', 'statusHistory.changedBy', 'assignedEmployee.user']);

        $employeeBranchIds = $employee?->branches()->pluck('branches.id');

        if (! $canSeeAllOrders && (! $employee || $employeeBranchIds->isEmpty())) {
            return Order::query()->whereRaw('1 = 0');
        }

        if (! $canSeeAllOrders) {
            $query->whereIn('branch_id', $employeeBranchIds);
        }

        if (! empty($filters['branch_id'])) {
            $bid = (int) $filters['branch_id'];
            if (! $canSeeAllOrders && $employee && ! $employee->branches()->where('branches.id', $bid)->exists()) {
                return Order::query()->whereRaw('1 = 0');
            }
            $query->where('branch_id', $bid);
        }

        if (! empty($filters['branch_name'])) {
            $query->whereHas('branch', fn ($q) => $q->where('name', 'like', '%'.$filters['branch_name'].'%')
                ->orWhere('area', 'like', '%'.$filters['branch_name'].'%'));
        }

        if (! empty($filters['staff_id'])) {
            $query->where('assigned_employee_id', $filters['staff_id']);
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['order_type'])) {
            $types = is_array($filters['order_type']) ? $filters['order_type'] : [$filters['order_type']];
            $query->whereIn('order_type', $types);
        }

        if (! empty($filters['order_source'])) {
            $sources = is_array($filters['order_source']) ? $filters['order_source'] : [$filters['order_source']];
            $query->whereIn('order_source', $sources);
        }

        if (! empty($filters['contact_phone'])) {
            $query->where('contact_phone', 'like', '%'.$filters['contact_phone'].'%');
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%'.$search.'%')
                    ->orWhere('contact_name', 'like', '%'.$search.'%')
                    ->orWhere('contact_phone', 'like', '%'.$search.'%')
                    ->orWhereHas('customer.user', fn ($uq) => $uq->where('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%'));
            });
        }

        if (! empty($filters['payment_status'])) {
            $statuses = is_array($filters['payment_status']) ? $filters['payment_status'] : [$filters['payment_status']];
            $query->whereHas('payments', fn ($q) => $q->whereIn('payment_status', $statuses));
        }

        if (! empty($filters['payment_method'])) {
            $methods = is_array($filters['payment_method']) ? $filters['payment_method'] : [$filters['payment_method']];
            $query->whereHas('payments', fn ($q) => $q->whereIn('payment_method', $methods));
        }

        return $query->latest();
    }

    /**
     * Get order statistics for employee's branch.
     */
    public function getBranchStats(User $user): array
    {
        $employee = $user->employee;

        if (! $employee) {
            return $this->emptyStats();
        }

        // Company-wide roles hold no branch, which is not the same as holding
        // none of them. Passing every branch id gives the call centre the
        // figures for the whole company, which is the scope of their job.
        // See User::isCompanyWide.
        $branchIds = $user->isCompanyWide()
            ? Branch::query()->pluck('id')->toArray()
            : $employee->branches()->pluck('branches.id')->toArray();

        if (empty($branchIds)) {
            return $this->emptyStats();
        }

        return $this->analyticsService->getEmployeeBranchStats($branchIds);
    }

    /**
     * Update order status with state machine validation.
     *
     * @param  \App\Models\User|null  $causer  User who performed the status change (e.g. employee)
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    public function updateOrderStatus(Order $order, string $status, ?string $notes = null, ?User $causer = null): Order
    {
        if (! $order->canTransitionTo($status)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => "Cannot transition order from '{$order->status}' to '{$status}'.",
                ], 422)
            );
        }

        $oldStatus = $order->status;

        $updateData = ['status' => $status];

        // Whoever accepts an order owns it.
        //
        // An order that arrived from anywhere but a till — the website, or a
        // call the branch did not take — is created with no employee against
        // it, so until somebody accepts it, it belongs to nobody. Every figure
        // that measures staff against sales reads `assigned_employee_id`
        // (see AnalyticsService::getStaffSalesMetrics), so an unclaimed order
        // reports as "Unassigned" revenue nobody is accountable for.
        //
        // Guarded on the column being empty: an order taken at a till, or by a
        // call-centre agent, already names its owner from creation, and
        // accepting it later must never reassign it away from them.
        $newlyAssignedTo = null;
        if (in_array($status, ['accepted', 'preparing']) && ! $order->assigned_employee_id && $causer?->employee) {
            $newlyAssignedTo = $causer->employee;
            $updateData['assigned_employee_id'] = $newlyAssignedTo->id;
        }

        $order->update($updateData);

        if ($newlyAssignedTo !== null) {
            $this->attachToOpenShift($order, $newlyAssignedTo);
        }

        if ($notes) {
            $order->statusHistory()->create([
                'status' => $status,
                'notes' => $notes,
                'changed_at' => now(),
            ]);
        }

        if ($causer) {
            activity('orders')
                ->causedBy($causer)
                ->performedOn($order)
                ->withProperties([
                    'old_status' => $oldStatus,
                    'new_status' => $status,
                    'notes' => $notes,
                    'assigned_employee_id' => $order->assigned_employee_id,
                ])
                ->event('status_changed')
                ->log("Order {$order->order_number} status changed to {$status}");
        }

        return $order->fresh();
    }

    /**
     * Put a newly-claimed order onto that employee's open shift.
     *
     * The till does this for its own sales from the client, the moment payment
     * clears. Nothing did it for an order claimed from the Order Manager or the
     * Kitchen Display, so accepting an online order made you its owner
     * everywhere except the one figure you are counted against at the end of
     * the day — your shift's takings.
     *
     * Deliberately quiet. This is bookkeeping hanging off a status change, and
     * an order that has already been accepted must not fail to accept because
     * its owner had not clocked in. Idempotent through `firstOrCreate`, so the
     * till's own call for the same order cannot double it.
     */
    protected function attachToOpenShift(Order $order, Employee $employee): void
    {
        try {
            $shift = Shift::query()
                ->where('employee_id', $employee->id)
                ->whereNull('logout_at')
                ->latest('login_at')
                ->first();

            if (! $shift) {
                return;
            }

            $orderTotal = (float) $order->total_amount;

            $shiftOrder = ShiftOrder::firstOrCreate(
                ['shift_id' => $shift->id, 'order_id' => $order->id],
                ['order_total' => $orderTotal],
            );

            if ($shiftOrder->wasRecentlyCreated) {
                $shift->increment('total_sales', $orderTotal);
                $shift->increment('order_count');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to attach accepted order to shift', [
                'order_id' => $order->id,
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pending orders for quick view.
     */
    public function getPendingOrders(User $user): Builder
    {
        $employee = $user->employee;

        if (! $employee) {
            return Order::query()->whereRaw('1 = 0');
        }

        // See getBranchStats — a company-wide role covers every branch.
        $branchIds = $user->isCompanyWide()
            ? Branch::query()->pluck('id')
            : $employee->branches()->pluck('branches.id');

        if ($branchIds->isEmpty()) {
            return Order::query()->whereRaw('1 = 0');
        }

        return Order::with(['customer.user', 'items.menuItemOption.menuItem', 'items.menuItem.category', 'branch'])
            ->whereIn('branch_id', $branchIds)
            ->paymentConfirmed()
            ->whereIn('status', ['received', 'accepted', 'preparing', 'ready', 'ready_for_pickup', 'out_for_delivery'])
            ->latest();
    }

    /**
     * Empty stats array.
     */
    protected function emptyStats(): array
    {
        return [
            'pending_orders' => 0,
            'preparing_orders' => 0,
            'today_orders' => 0,
            'today_revenue' => 0,
            'completed_today' => 0,
        ];
    }

    /**
     * Lightweight period summary for the current branch-orders filter scope.
     *
     * Uses the same filter pipeline as getBranchOrders() so the summary always
     * matches what the user is viewing in the table.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     valid_count: int,
     *     valid_revenue: float,
     *     cancelled_count: int,
     *     cancelled_amount: float,
     *     failed_count: int,
     *     failed_amount: float,
     *     refunded_count: int,
     *     refunded_amount: float,
     *     no_charge_count: int,
     *     no_charge_amount: float,
     *     issues_count: int,
     *     issues_amount: float,
     *     total_count: int
     * }
     */
    public function getBranchPeriodSummary(User $user, array $filters = []): array
    {
        // Reuse the existing scoped query (handles role/branch access + filters).
        $base = $this->getBranchOrders($user, $filters);

        // Strip the eager loads + ordering — irrelevant for aggregations and they slow it down.
        $base->getQuery()->orders = null;
        $base->setEagerLoads([]);

        $hasPayment = fn (string $status) => fn ($q) => $q->where('payment_status', $status);

        $validQuery = (clone $base)->where('status', '!=', 'cancelled')
            ->whereHas('payments', $hasPayment('completed'));
        $cancelledQuery = (clone $base)->where('status', 'cancelled');
        $failedQuery = (clone $base)->where('status', '!=', 'cancelled')
            ->whereHas('payments', $hasPayment('failed'))
            ->whereDoesntHave('payments', $hasPayment('completed'));
        $refundedQuery = (clone $base)->whereHas('payments', $hasPayment('refunded'));
        $noChargeQuery = (clone $base)->where('status', '!=', 'cancelled')
            ->whereHas('payments', $hasPayment('no_charge'));

        // Distinct "issue" orders: each order counted once even if it qualifies
        // under multiple buckets (e.g. cancelled AND refunded). Amount is the
        // order's total_amount summed once per order.
        $issuesQuery = (clone $base)->where(function ($q) use ($hasPayment): void {
            $q->where('status', 'cancelled')
                ->orWhereHas('payments', $hasPayment('refunded'))
                ->orWhere(function ($q2) use ($hasPayment): void {
                    $q2->whereHas('payments', $hasPayment('failed'))
                        ->whereDoesntHave('payments', $hasPayment('completed'));
                });
        });

        return [
            'valid_count' => (int) (clone $validQuery)->count(),
            'valid_revenue' => round((float) (clone $validQuery)->sum('total_amount'), 2),
            'cancelled_count' => (int) (clone $cancelledQuery)->count(),
            'cancelled_amount' => round((float) (clone $cancelledQuery)->sum('total_amount'), 2),
            'failed_count' => (int) (clone $failedQuery)->count(),
            'failed_amount' => round((float) (clone $failedQuery)->sum('total_amount'), 2),
            'refunded_count' => (int) (clone $refundedQuery)->count(),
            'refunded_amount' => round((float) (clone $refundedQuery)->sum('total_amount'), 2),
            'no_charge_count' => (int) (clone $noChargeQuery)->count(),
            'no_charge_amount' => round((float) (clone $noChargeQuery)->sum('total_amount'), 2),
            'issues_count' => (int) (clone $issuesQuery)->count(),
            'issues_amount' => round((float) (clone $issuesQuery)->sum('total_amount'), 2),
            'total_count' => (int) (clone $base)->count(),
        ];
    }
}
