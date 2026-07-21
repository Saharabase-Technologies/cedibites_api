<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\DailyClosingResource;
use App\Models\Inventory\DailyClosing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyClosingController extends Controller
{
    private const RELATIONS = [
        'location',
        'lines.item.baseUnit',
        'lines.unit',
        'openedBy',
        'completedBy',
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
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'business_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $closing = $this->service->open($data['location_id'], $data['business_date'], $request->user());

            return response()->success($this->fresh($closing), 'Daily closing opened.');
        });
    }

    /** Record counted quantities and optionally complete the closing. */
    public function update(Request $request, DailyClosing $dailyClosing): JsonResponse
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['required', 'numeric', 'gte:0'],
            'complete' => ['sometimes', 'boolean'],
        ]);
        $counts = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['counted_qty']])->all();

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

    /** Calendar of closing coverage for a location — flags missed days. */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $days = $this->service->calendar($data['location_id'], $data['from'], $data['to']);

        return response()->success($days);
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
