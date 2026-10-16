<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Shifts\ShiftSales;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff Sales by shift: the manager's for their branch, the admin's for every
 * branch (staff portal Section 7). The period is whole days, the server's,
 * today when none is given.
 */
class ShiftSalesController extends Controller
{
    public function __construct(private ShiftSales $sales) {}

    /** GET /manager/branches/{branch}/shift-sales. `branch.access` has checked the branch. */
    public function forBranch(Request $request, Branch $branch): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->success($this->sales->list([$branch->id], $from, $to));
    }

    /** GET /admin/staff/shift-sales, every branch or one. */
    public function forAdmin(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer', 'exists:branches,id']]);
        [$from, $to] = $this->period($request);

        return response()->success($this->sales->list(
            $request->filled('branch_id') ? [(int) $request->integer('branch_id')] : null,
            $from,
            $to,
        ));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $from = Carbon::parse($validated['date_from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($validated['date_to'] ?? $from->toDateString())->endOfDay();

        return [$from, $to];
    }
}
