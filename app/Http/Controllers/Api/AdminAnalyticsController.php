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

        $this->restrictPartnerBranchScope($request, $filters);

        return $filters;
    }

    /**
     * Branch partners may only see analytics for branches they are assigned to.
     *
     * Intersects any requested branch scope with the partner's assigned
     * branches (defaulting to all assigned when none is specified), so a
     * crafted branch_id cannot leak another branch's figures. Admins,
     * managers and other roles are unaffected.
     */
    private function restrictPartnerBranchScope(Request $request, array &$filters): void
    {
        $user = $request->user();
        if (! $user || ! $user->hasRole('branch_partner')) {
            return;
        }

        $assigned = $user->employee
            ? $user->employee->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all()
            : [];

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
            'subjects.*.label' => ['nullable', 'string', 'max:80'],
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
