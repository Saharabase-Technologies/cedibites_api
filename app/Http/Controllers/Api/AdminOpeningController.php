<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchOpeningResource;
use App\Models\Branch;
use App\Models\BranchOpening;
use App\Models\OpeningChecklistItem;
use App\Services\Openings\BranchOpeningService;
use App\Services\Openings\OpeningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Head office's side of opening the branches: how every branch started the
 * day, opening one without its checklist, choosing which branches use the
 * checklist, and what the checklist asks.
 */
class AdminOpeningController extends Controller
{
    public function __construct(
        private readonly BranchOpeningService $openings,
    ) {}

    /** Every active branch for one business day, today by default. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $this->openings->businessDate();

        $branches = Branch::query()->where('is_active', true)->with('operatingHours')->orderBy('name')->get();
        $openings = BranchOpening::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->whereDate('business_date', $date)
            ->get()
            ->keyBy('branch_id');

        return response()->success([
            'business_date' => $date,
            'today' => $this->openings->businessDate(),
            'branches' => $branches->map(fn (Branch $branch) => (new BranchOpeningResource(
                $branch,
                $openings->get($branch->id),
                withAnswers: false,
            ))->resolve($request) + [
                'requires_opening_checklist' => (bool) $branch->requires_opening_checklist,
            ])->values(),
        ]);
    }

    public function show(BranchOpening $opening): JsonResponse
    {
        return response()->success(new BranchOpeningResource($opening->branch, $opening));
    }

    /**
     * Open a branch without its checklist. Unusual on purpose: a reason is
     * required, it is logged as unusual, and head office is texted.
     */
    public function openWithoutChecklist(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        try {
            $opening = $this->openings->openByHeadOffice($branch, $request->user(), $data['reason']);
        } catch (OpeningException $e) {
            return response()->json(['code' => $e->reason, 'message' => $e->getMessage()], 422);
        }

        return response()->success(new BranchOpeningResource($branch, $opening));
    }

    /** Switch a branch onto the opening checklist, or off it. */
    public function setRequirement(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate(['required' => ['required', 'boolean']]);

        // Recorded by the branch's own activity log, which watches this field.
        $branch->update(['requires_opening_checklist' => $data['required']]);
        $this->openings->broadcast($branch);

        return response()->success([
            'branch_id' => $branch->id,
            'requires_opening_checklist' => (bool) $branch->requires_opening_checklist,
        ], $data['required']
            ? "{$branch->name} now has to be opened with the checklist before it sells."
            : "{$branch->name} no longer uses the opening checklist.");
    }

    // ── The checklist itself ───────────────────────────────────────────────

    public function checklist(): JsonResponse
    {
        return response()->success(OpeningChecklistItem::query()->orderBy('position')->orderBy('id')->get());
    }

    public function storeItem(Request $request): JsonResponse
    {
        $data = $this->validateItem($request, creating: true);

        $item = OpeningChecklistItem::create($data + [
            'key' => $this->uniqueKey($data['label']),
            'position' => (int) OpeningChecklistItem::query()->where('section', $data['section'])->max('position') + 5,
        ]);

        return response()->created($item);
    }

    /**
     * Change a line. Tomorrow's checklist changes; no earlier day's does,
     * because each opening kept its own copy.
     */
    public function updateItem(Request $request, OpeningChecklistItem $item): JsonResponse
    {
        $item->update($this->validateItem($request, creating: false));

        return response()->success($item->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validateItem(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'section' => [$required, 'string', 'max:60'],
            'group' => ['nullable', 'string', 'max:60'],
            'label' => [$required, 'string', 'max:255'],
            'short' => [$required, 'string', 'max:60'],
            'help' => ['nullable', 'string', 'max:255'],
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(['check', 'number', 'text'])],
            'weight' => ['sometimes', Rule::in(['must_pass', 'can_open', 'record'])],
            'allows_na' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65000'],
        ]);

        // Only a yes-or-no line can fail. A number or a note is for the record.
        $kind = $data['kind'] ?? $request->route('item')?->kind;
        if ($kind !== null && $kind !== 'check') {
            $data['weight'] = 'record';
            $data['allows_na'] = false;
        } elseif (($data['weight'] ?? null) === 'record') {
            $data['weight'] = 'can_open';
        }

        return $data;
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::limit(Str::slug($label, '_'), 50, '');
        $key = $base;
        $n = 2;

        while (OpeningChecklistItem::query()->where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }
}
