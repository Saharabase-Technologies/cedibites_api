<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Requisitions\RequisitionService;
use App\Events\Inventory\RequisitionBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\RequisitionResource;
use App\Models\Inventory\Requisition;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequisitionController extends Controller
{
    private const RELATIONS = [
        'requestingLocation',
        'sourceLocation',
        'lines.item.baseUnit',
        'lines.unit',
        'fulfillingTransfer',
        'requestedBy',
        'approvedBy',
    ];

    public function __construct(
        private readonly RequisitionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requisitions = Requisition::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('requesting_location_id'), fn ($q) => $q->where('requesting_location_id', $request->integer('requesting_location_id')))
            ->when($request->filled('source_location_id'), fn ($q) => $q->where('source_location_id', $request->integer('source_location_id')))
            ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->string('purpose')))
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->get();

        return response()->success(RequisitionResource::collection($requisitions));
    }

    public function show(Request $request, Requisition $requisition): JsonResponse
    {
        // 404 rather than 403 — an out-of-scope requisition should not be
        // confirmed to exist.
        abort_unless($requisition->isVisibleTo($request->user()), 404);

        return response()->success(new RequisitionResource($requisition->load(self::RELATIONS)));
    }

    public function store(Request $request): JsonResponse
    {
        // `requesting_location_id` is optional: a branch manager is by definition
        // requesting for their own branch, so the server fills it in. It stays
        // accepted for unrestricted users (warehouse manager, admin), who may
        // raise a requisition on any branch's behalf.
        $data = $request->validate([
            'requesting_location_id' => ['sometimes', 'integer', 'exists:inventory_locations,id'],
            'source_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'purpose' => ['nullable', 'in:opening,supplementary'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $data['requesting_location_id'] = $this->resolveRequestingLocation(
                $request->user(),
                isset($data['requesting_location_id']) ? (int) $data['requesting_location_id'] : null,
            );

            $requisition = $this->service->create($data, $request->user());
            $this->broadcast($requisition, 'created');

            return response()->success($this->fresh($requisition), 'Requisition created.');
        });
    }

    /**
     * Settle which branch a new requisition is for, and refuse to create one the
     * requester could not then read back.
     *
     * Creation used to accept any location that merely existed while `show()`
     * enforced the location scope, so a branch manager could file a requisition
     * against a branch outside their scope and be met with a 404 on the record
     * they had just created.
     */
    private function resolveRequestingLocation(User $actor, ?int $requested): int
    {
        $ids = $actor->accessibleLocationIds();

        // Unrestricted — they could mean any branch, so they must say which.
        if ($ids === null) {
            if ($requested === null) {
                throw new InventoryException('Choose the branch this requisition is for.');
            }

            return $requested;
        }

        if ($ids === []) {
            throw new InventoryException(
                'Your account is not linked to an inventory location, so it cannot raise requisitions. '
                .'Ask an administrator to assign you to a branch and to give that branch an inventory location.'
            );
        }

        $requested ??= $actor->defaultInventoryLocationId();

        if ($requested === null) {
            throw new InventoryException('Choose which of your branches this requisition is for.');
        }

        if (! in_array($requested, array_map('intval', $ids), true)) {
            throw new InventoryException('You can only raise requisitions for your own branch.');
        }

        return $requested;
    }

    public function update(Request $request, Requisition $requisition): JsonResponse
    {
        // No `different:requesting_location_id` here — that column is never in an
        // update payload, so the rule always passed vacuously. The service
        // compares against the stored requesting location instead.
        $data = $request->validate([
            'source_location_id' => ['sometimes', 'integer', 'exists:inventory_locations,id'],
            'purpose' => ['sometimes', 'in:opening,supplementary'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', 'exists:inventory_items,id'],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'gt:0'],
        ]);

        return $this->guard(function () use ($requisition, $data) {
            $updated = $this->service->update($requisition, $data);
            $this->broadcast($updated, 'updated');

            return response()->success($this->fresh($updated), 'Requisition updated.');
        });
    }

    /** draft → submitted. */
    public function submit(Request $request, Requisition $requisition): JsonResponse
    {
        return $this->guard(function () use ($requisition, $request) {
            $updated = $this->service->submit($requisition, $request->user());
            $this->broadcast($updated, 'submitted');

            return response()->success($this->fresh($updated), 'Requisition submitted.');
        });
    }

    /** submitted → approved (spawns a fulfilling transfer). */
    public function approve(Request $request, Requisition $requisition): JsonResponse
    {
        $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required_with:lines', 'integer'],
            'lines.*.approved_qty' => ['required_with:lines', 'numeric', 'gte:0'],
        ]);
        $approvedQty = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['approved_qty']])->all();

        return $this->guard(function () use ($requisition, $request, $approvedQty) {
            $updated = $this->service->approve($requisition, $request->user(), $approvedQty);
            $this->broadcast($updated, 'approved');

            return response()->success($this->fresh($updated), 'Requisition approved — fulfilling transfer created.');
        });
    }

    /** submitted → rejected. */
    public function reject(Request $request, Requisition $requisition): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard(function () use ($requisition, $request, $data) {
            $updated = $this->service->reject($requisition, $request->user(), $data['reason']);
            $this->broadcast($updated, 'rejected');

            return response()->success($this->fresh($updated), 'Requisition rejected.');
        });
    }

    private function fresh(Requisition $requisition): RequisitionResource
    {
        return new RequisitionResource($requisition->fresh(self::RELATIONS));
    }

    /** Fan out a lightweight change signal so every listening screen refetches. */
    private function broadcast(Requisition $requisition, string $changeType): void
    {
        RequisitionBroadcastEvent::dispatch(
            $requisition->id,
            $requisition->reference,
            $requisition->status->value,
            $changeType,
        );
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
