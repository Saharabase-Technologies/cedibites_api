<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService,
    ) {}

    /**
     * Build the standard analytics filter set from the request.
     *
     * Supports a single `branch_id` or a `branch_ids[]` array (used by the
     * partner portal to aggregate across a partner's assigned branches).
     */
    private function filters(Request $request): array
    {
        $filters = $request->only(['date_from', 'date_to', 'branch_id']);

        $branchIds = $request->input('branch_ids');
        if (is_array($branchIds) && count($branchIds) > 0) {
            $filters['branch_ids'] = array_values(array_map('intval', $branchIds));
        }

        $this->restrictBranchScope($request, $filters);

        return $filters;
    }

    /**
     * Nobody below Admin sees a branch they are not assigned to.
     *
     * Intersects any requested branch scope with the caller's own branches
     * (defaulting to all of them when none is specified), so a crafted
     * `branch_id` cannot leak another branch's figures. The scope is decided
     * here, on the server, because `branch_id` is otherwise whatever the client
     * chose to send — the manager portal fills it from `staffUser.branches[0]`
     * and the admin portal from a `?branch=` query param, and dropping the
     * parameter altogether used to return company-wide totals to anyone holding
     * `view_orders`, which is every staff role.
     *
     * Only admin and tech_admin sit above the branch structure. Everyone else —
     * manager, branch partner, and any future role — is confined to their
     * assignment, and a user with no branches at all sees nothing.
     */
    private function restrictBranchScope(Request $request, array &$filters): void
    {
        $assigned = $this->assignedBranchIds($request);

        if ($assigned === null) {
            return; // admin — no confinement
        }

        if (empty($assigned)) {
            $filters['branch_ids'] = [-1]; // assigned to nothing → see nothing
            unset($filters['branch_id']);

            return;
        }

        $requested = $filters['branch_ids']
            ?? (isset($filters['branch_id']) ? [(int) $filters['branch_id']] : []);

        $allowed = empty($requested)
            ? $assigned
            : array_values(array_intersect($requested, $assigned));

        $filters['branch_ids'] = empty($allowed) ? [-1] : $allowed;
        unset($filters['branch_id']);
    }

    /**
     * The branches this caller may see figures for.
     *
     * `null` means "every branch" and is reserved for admin and tech_admin. An
     * empty array means the caller is confined to nothing — a staff member with
     * no branch assignment — and callers must render that as no data rather
     * than as no restriction.
     *
     * @return list<int>|null
     */
    private function assignedBranchIds(Request $request): ?array
    {
        $user = $request->user();

        if ($user?->hasAnyRole(['admin', 'tech_admin'])) {
            return null;
        }

        return $user?->employee
            ? $user->employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    public function sales(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getSalesMetrics($this->filters($request)),
            'Sales analytics retrieved successfully.'
        );
    }

    public function salesComparison(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getSalesComparison($this->filters($request)),
            'Sales comparison retrieved successfully.'
        );
    }

    public function revenueTrend(Request $request): JsonResponse
    {
        $bucket = $request->string('bucket')->toString() ?: null;

        return response()->success(
            $this->analyticsService->getRevenueTrend($this->filters($request), $bucket),
            'Revenue trend retrieved successfully.'
        );
    }

    public function orders(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getOrderMetrics($filters),
            'Order analytics retrieved successfully.'
        );
    }

    public function customers(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getCustomerMetrics($filters),
            'Customer analytics retrieved successfully.'
        );
    }

    public function orderSources(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getSourceMetrics($filters),
            'Order source analytics retrieved successfully.'
        );
    }

    public function topItems(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $limit = $request->integer('limit', 10);

        return response()->success(
            $this->analyticsService->getTopItemsMetrics($filters, $limit),
            'Top items analytics retrieved successfully.'
        );
    }

    public function bottomItems(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $limit = $request->integer('limit', 5);

        return response()->success(
            $this->analyticsService->getBottomItemsMetrics($filters, $limit),
            'Bottom items analytics retrieved successfully.'
        );
    }

    public function categoryRevenue(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getCategoryRevenueMetrics($filters),
            'Category revenue analytics retrieved successfully.'
        );
    }

    public function branchPerformance(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getBranchMetrics($filters),
            'Branch performance analytics retrieved successfully.'
        );
    }

    public function deliveryPickup(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getDeliveryPickupMetrics($filters),
            'Delivery vs pickup analytics retrieved successfully.'
        );
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getPaymentMethodMetrics($filters),
            'Payment method analytics retrieved successfully.'
        );
    }

    // ─── NEW ENDPOINTS ──────────────────────────────────────────────

    public function fulfillment(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getFulfillmentMetrics($filters),
            'Fulfillment analytics retrieved successfully.'
        );
    }

    public function promos(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getPromoMetrics($filters),
            'Promo analytics retrieved successfully.'
        );
    }

    public function discountUsage(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getDiscountUsageMetrics($filters),
            'Discount usage analytics retrieved successfully.'
        );
    }

    public function cancellationReasons(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getCancellationReasonsMetrics($filters),
            'Cancellation reasons analytics retrieved successfully.'
        );
    }

    public function checkoutFunnel(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getFunnelMetrics($filters),
            'Checkout funnel analytics retrieved successfully.'
        );
    }

    public function staffSales(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getStaffSalesMetrics($filters),
            'Staff sales analytics retrieved successfully.'
        );
    }

    /**
     * Menu catalog (items + options) for the comparison picker.
     */
    public function menuCatalog(): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getMenuCatalog(),
            'Menu catalog retrieved successfully.'
        );
    }

    /**
     * Menu comparison — aggregate historical sales for assembled subjects.
     */
    public function menuComparison(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjects' => ['required', 'array', 'min:1', 'max:4'],
            'subjects.*.label' => ['nullable', 'string', 'max:255'],
            'subjects.*.item_ids' => ['nullable', 'array'],
            'subjects.*.item_ids.*' => ['integer'],
            'subjects.*.option_ids' => ['nullable', 'array'],
            'subjects.*.option_ids.*' => ['integer'],
        ]);

        $filters = $this->filters($request);

        return response()->success(
            $this->analyticsService->getMenuComparison($filters, $validated['subjects']),
            'Menu comparison retrieved successfully.'
        );
    }

    /**
     * Repeat-customer health (new vs returning, repeat rate, cadence).
     */
    public function repeatCustomers(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getRepeatCustomerMetrics($this->filters($request)),
            'Repeat customer analytics retrieved successfully.'
        );
    }

    /**
     * Customer lifecycle: lifetime value, churn buckets, retention cohorts.
     */
    public function customerLifecycle(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getCustomerLifecycleMetrics($this->filters($request)),
            'Customer lifecycle analytics retrieved successfully.'
        );
    }

    /**
     * Basket affinity — items frequently bought together.
     */
    public function basketAffinity(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getBasketAffinityMetrics($this->filters($request)),
            'Basket affinity analytics retrieved successfully.'
        );
    }

    /**
     * List per-branch revenue targets for a given year/month.
     *
     * Scoped like every other figure here: a manager sees the target for the
     * branch he runs, not the whole company's league table.
     */
    public function getRevenueTargets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $assigned = $this->assignedBranchIds($request);

        $rows = \App\Models\BranchRevenueTarget::with('branch:id,name')
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->when($assigned !== null, fn ($q) => $q->whereIn('branch_id', $assigned ?: [-1]))
            ->get()
            ->map(fn ($t) => [
                'branch_id' => (int) $t->branch_id,
                'branch_name' => $t->branch->name ?? 'Unknown',
                'year' => (int) $t->year,
                'month' => (int) $t->month,
                'target_amount' => round((float) $t->target_amount, 2),
            ]);

        return response()->success($rows, 'Revenue targets retrieved successfully.');
    }

    /**
     * Create or update a single branch's monthly revenue target. Admin only.
     */
    public function setRevenueTarget(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['admin', 'tech_admin'])) {
            return response()->json(['message' => 'Only administrators can set revenue targets.'], 403);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'year' => ['required', 'integer', 'min:2024', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'target_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $target = \App\Models\BranchRevenueTarget::updateOrCreate(
            [
                'branch_id' => $validated['branch_id'],
                'year' => $validated['year'],
                'month' => $validated['month'],
            ],
            ['target_amount' => $validated['target_amount']],
        );
        $target->load('branch:id,name');

        return response()->success([
            'branch_id' => (int) $target->branch_id,
            'branch_name' => $target->branch->name ?? 'Unknown',
            'year' => (int) $target->year,
            'month' => (int) $target->month,
            'target_amount' => round((float) $target->target_amount, 2),
        ], 'Revenue target saved successfully.');
    }

    /**
     * Per-item demand forecast (projected units for the next N days).
     */
    public function demandForecast(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getDemandForecastMetrics($this->filters($request)),
            'Demand forecast retrieved successfully.'
        );
    }

    /**
     * Per-branch monthly revenue target vs actual (defaults to current month).
     */
    public function targetsVsActual(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $filters = $this->filters($request);
        $branchIds = $filters['branch_ids']
            ?? (isset($filters['branch_id']) ? [(int) $filters['branch_id']] : null);

        return response()->success(
            $this->analyticsService->getTargetsVsActualMetrics($year, $month, $branchIds),
            'Targets vs actual retrieved successfully.'
        );
    }

    /**
     * Orders by weekday × hour (2-D demand heatmap).
     */
    public function weekdayHour(Request $request): JsonResponse
    {
        return response()->success(
            $this->analyticsService->getWeekdayHourMetrics($this->filters($request)),
            'Weekday-hour analytics retrieved successfully.'
        );
    }
}
