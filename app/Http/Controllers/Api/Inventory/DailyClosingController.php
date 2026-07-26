<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Enums\Inventory\WastageReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\DailyClosingResource;
use App\Models\Inventory\DailyClosing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DailyClosingController extends Controller
{
    private const RELATIONS = [
        'location',
        'lines.item.baseUnit',
        'lines.unit',
        'openedBy',
        'completedBy',
        'wastage',
    ];

    public function __construct(
        private readonly DailyClosingService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $closings = DailyClosing::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('business_date', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('business_date', '<=', $request->string('date_to')))
            ->orderByDesc('business_date')
            ->get();

        return response()->success(DailyClosingResource::collection($closings));
    }

    public function show(Request $request, DailyClosing $dailyClosing): JsonResponse
    {
        abort_unless($dailyClosing->isVisibleTo($request->user()), 404);

        return response()->success(new DailyClosingResource($dailyClosing->load(self::RELATIONS)));
    }

    /** Open (or return) a closing for a location + date, snapshotting expected qtys. */
    public function store(Request $request): JsonResponse
    {
        // The date is no longer the client's to choose - a count is always for
        // the current BUSINESS day, which before 03:00 is still yesterday's.
        // Still accepted so an older client gets the service's explanation
        // rather than a silent surprise, but the service is the authority.
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'business_date' => ['sometimes', 'date'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $closing = $this->service->open(
                $data['location_id'],
                $data['business_date'] ?? DailyClosingService::currentBusinessDate(),
                $request->user(),
            );

            return response()->success($this->fresh($closing), 'Daily closing opened.');
        });
    }

    /**
     * Record counted quantities and optionally complete the closing.
     *
     * A shortfall may carry a reason, which files it in the wastage report under
     * what actually happened. Optional on purpose: a day must always be able to
     * close, so an unexplained shortfall stays visibly unexplained rather than
     * blocking the count or being dressed up as something it is not.
     */
    public function update(Request $request, DailyClosing $dailyClosing): JsonResponse
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['required', 'numeric', 'gte:0'],
            'lines.*.reason' => ['sometimes', 'nullable', 'string', Rule::enum(WastageReason::class)],
            'lines.*.reason_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'complete' => ['sometimes', 'boolean'],
        ]);
        $counts = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => [
                'counted_qty' => (float) $l['counted_qty'],
                'reason' => $l['reason'] ?? null,
                'reason_note' => $l['reason_note'] ?? null,
            ]])->all();

        return $this->guard(function () use ($dailyClosing, $counts, $request) {
            $closing = $this->service->saveCounts(
                $dailyClosing,
                $counts,
                $request->boolean('complete'),
                $request->user(),
            );

            return response()->success($this->fresh($closing), 'Daily closing saved.');
        });
    }

    /** Calendar of closing coverage for a location - flags missed days. */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $days = $this->service->calendar($data['location_id'], $data['from'], $data['to']);

        // The screen must not work this out for itself. `new Date()` in the
        // browser is the DEVICE's clock and timezone - a laptop left on the
        // wrong zone, or simply used at 02:50, would disagree with the server
        // about which day is being counted. The server owns the answer.
        return response()->success([
            'business_date' => DailyClosingService::currentBusinessDate(),
            'days' => $days,
        ]);
    }

    private function fresh(DailyClosing $closing): DailyClosingResource
    {
        return new DailyClosingResource($closing->fresh(self::RELATIONS));
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (InventoryException $e) {
            return response()->error($e->getMessage(), 422);
        }
    }
}
