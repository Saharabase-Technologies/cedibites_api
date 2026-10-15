<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesToAssignedBranches;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    use ScopesToAssignedBranches;

    public function __construct(
        protected FinanceLedgerService $ledgerService,
    ) {}

    /**
     * The rows behind every Finance screen, for the branches the caller may see.
     *
     * `from` and `to` are whole days, by the day the order was placed. Leave
     * both out for everything since the first sale.
     */
    public function ledger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('from'), ['after_or_equal:from'])],
            'branch_id' => ['nullable', 'integer'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer'],
        ]);

        // The branch scope is what the trait decides, and it is read from
        // nowhere else. A partner who asks for a branch that is not theirs
        // gets `[-1]`, which matches nothing. It is never read as "no filter".
        $filters = Arr::only($this->filters($request), ['branch_id', 'branch_ids']);

        if (isset($validated['from'])) {
            $filters['date_from'] = $validated['from'];
        }

        if (isset($validated['to'])) {
            $filters['date_to'] = $validated['to'];
        }

        return response()->success(
            $this->ledgerService->ledger($filters),
            'Finance ledger retrieved successfully.'
        );
    }
}
