<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Analytics\AnalyticsQueryBuilder;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
    ) {}

    /**
     * Get admin dashboard data: KPIs, branch stats, live orders.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Admins sit above the branch structure; everyone else sees only the
        // branches they are assigned to. Without this the dashboard handed out
        // today's revenue for every branch plus the last ten live orders
        // company-wide to anyone who could open it.
        $isAdmin = $user?->hasAnyRole(['admin', 'tech_admin']) ?? false;
        $assignedBranchIds = $isAdmin
            ? null
            : ($user?->employee?->branches()->pluck('branches.id')->all() ?? []);

        $kpis = $this->analyticsService->getDashboardMetrics();

        $activeBranches = Branch::where('is_active', true)
            ->when($assignedBranchIds !== null, fn ($q) => $q->whereIn('id', $assignedBranchIds ?: [-1]))
            ->get();
        $branchStats = $this->analyticsService->getBranchTodayStatsBulk(
            $activeBranches->pluck('id')->all()
        );

        $branches = $activeBranches->map(function (Branch $branch) use ($branchStats) {
            $stats = $branchStats[$branch->id] ?? ['revenue_today' => 0, 'orders_today' => 0];

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'status' => 'open',
                'revenue_today' => $stats['revenue_today'],
                'orders_today' => $stats['orders_today'],
            ];
        });
        $liveOrders = Order::with(['customer.user', 'branch', 'assignedEmployee.user'])
            ->paymentConfirmed()
            ->whereIn('status', AnalyticsQueryBuilder::ACTIVE_STATUSES)
            ->when($assignedBranchIds !== null, fn ($q) => $q->whereIn('branch_id', $assignedBranchIds ?: [-1]))
            ->latest()
            ->limit(10)
            ->get();

        $liveOrdersFormatted = $liveOrders->map(function (Order $order) {
            $createdAt = $order->created_at;
            $mins = $createdAt ? (int) now()->diffInMinutes($createdAt) : 0;
            $timeAgo = $mins < 60 ? "{$mins} min" : (int) ($mins / 60).' hr';

            return [
                'id' => $order->order_number,
                'customer' => $order->contact_name ?? $order->customer?->user?->name ?? '—',
                'branch' => $order->branch?->name ?? '—',
                'source' => ucfirst($order->order_source ?? 'online'),
                'status' => $order->status,
                'time_ago' => $timeAgo,
                'amount' => (float) $order->total_amount,
                'assigned_employee' => $order->assignedEmployee?->user?->name ?? null,
            ];
        });

        return response()->success([
            'user_name' => $user->name ?? 'Admin',
            'kpis' => $kpis,
            'branches' => $branches,
            'live_orders' => $liveOrdersFormatted,
        ], 'Dashboard data retrieved successfully.');
    }
}
