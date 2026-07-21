<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Reconciliation\ReconciliationService;
use App\Events\Inventory\ReconciliationBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\ReconciliationCycleResource;
use App\Models\Inventory\ReconciliationCycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    private const RELATIONS = [
        'location',
        'lines.item.baseUnit',
        'lines.unit',
        'openedBy',
        'closedBy',
    ];

    public function __construct(
        private readonly ReconciliationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $cycles = ReconciliationCycle::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('opened_at')
            ->get();

        return response()->success(ReconciliationCycleResource::collection($cycles));
    }

    public function show(Request $request, ReconciliationCycle $reconciliation): JsonResponse
    {
        abort_unless($reconciliation->isVisibleTo($request->user()), 404);

        return response()->success(new ReconciliationCycleResource($reconciliation->load(self::RELATIONS)));
    }

    /** Open a cycle for a location, snapshotting system quantities. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $cycle = $this->service->open($data['location_id'], $request->user());
            $this->broadcast($cycle, 'opened');

            return response()->success($this->fresh($cycle), 'Reconciliation cycle opened.');
        });
    }

    /** Record physical counts (does not post adjustments). */
    public function update(Request $request, ReconciliationCycle $reconciliation): JsonResponse
    {
        $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.counted_qty' => ['required', 'numeric', 'gte:0'],
        ]);
        $counts = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['counted_qty']])->all();

        return $this->guard(function () use ($reconciliation, $counts) {
            $cycle = $this->service->saveCounts($reconciliation, $counts);
            $this->broadcast($cycle, 'counted');

            return response()->success($this->fresh($cycle), 'Counts saved.');
        });
    }

    /** Post the reconciliation — write cycle_adjustment movements and close it. */
    public function post(Request $request, ReconciliationCycle $reconciliation): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->guard(function () use ($reconciliation, $request, $data) {
            $cycle = $this->service->post($reconciliation, $request->user(), $data['notes'] ?? null);
            $this->broadcast($cycle, 'posted');

            return response()->success($this->fresh($cycle), 'Reconciliation posted — the books are balanced.');
        });
    }

    private function fresh(ReconciliationCycle $cycle): ReconciliationCycleResource
    {
        return new ReconciliationCycleResource($cycle->fresh(self::RELATIONS));
    }

    private function broadcast(ReconciliationCycle $cycle, string $changeType): void
    {
        ReconciliationBroadcastEvent::dispatch($cycle->id, $cycle->status->value, $changeType);
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
