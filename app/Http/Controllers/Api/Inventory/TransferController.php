<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Transfers\TransferService;
use App\Enums\Permission;
use App\Events\Inventory\RequisitionBroadcastEvent;
use App\Events\Inventory\TransferBroadcastEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\TransferResource;
use App\Models\Inventory\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    private const RELATIONS = [
        'sourceLocation',
        'destinationLocation',
        'lines.item.baseUnit',
        'lines.unit',
        'dispute',
        'createdBy',
        'approvedBy',
        'sentBy',
        'receivedBy',
        'cancelledBy',
    ];

    public function __construct(
        private readonly TransferService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $transfers = Transfer::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('source_location_id'), fn ($q) => $q->where('source_location_id', $request->integer('source_location_id')))
            ->when($request->filled('destination_location_id'), fn ($q) => $q->where('destination_location_id', $request->integer('destination_location_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('search').'%'))
            ->latest()
            ->get();

        return response()->success(TransferResource::collection($transfers));
    }

    public function show(Request $request, Transfer $transfer): JsonResponse
    {
        // 404 rather than 403 — an out-of-scope transfer should not be
        // confirmed to exist.
        abort_unless($transfer->isVisibleTo($request->user()), 404);

        return response()->success(new TransferResource($transfer->load(self::RELATIONS)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $transfer = $this->service->create($data, $request->user());
            $this->broadcast($transfer, 'created');

            return response()->success($this->fresh($transfer), 'Transfer created.');
        });
    }

    public function update(Request $request, Transfer $transfer): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'integer', 'exists:inventory_items,id'],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'gt:0'],
        ]);

        return $this->guard(function () use ($transfer, $data) {
            $updated = $this->service->update($transfer, $data);
            $this->broadcast($updated, 'updated');

            return response()->success($this->fresh($updated), 'Transfer updated.');
        });
    }

    /** draft → submitted (source-stock validated; admin may override the deficit). */
    public function submit(Request $request, Transfer $transfer): JsonResponse
    {
        $override = $request->boolean('override_source_check');
        if ($override && $request->user()->cannot(Permission::InventoryTransferOverrideSourceCheck->value)) {
            return response()->forbidden('Overriding the source-stock check requires admin permission.');
        }

        return $this->guard(function () use ($transfer, $request, $override) {
            $updated = $this->service->submit($transfer, $request->user(), $override);
            $this->broadcast($updated, 'submitted');

            return response()->success($this->fresh($updated), 'Transfer submitted.');
        });
    }

    /** submitted → approved (release authority — gated by transfer.send). */
    public function approve(Request $request, Transfer $transfer): JsonResponse
    {
        return $this->guard(function () use ($transfer, $request) {
            $updated = $this->service->approve($transfer, $request->user());
            $this->broadcast($updated, 'approved');

            return response()->success($this->fresh($updated), 'Transfer approved.');
        });
    }

    /** approved → sent (deducts source, FEFO). */
    public function send(Request $request, Transfer $transfer): JsonResponse
    {
        $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required_with:lines', 'integer'],
            'lines.*.sent_qty' => ['required_with:lines', 'numeric', 'gt:0'],
        ]);
        $sentQty = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['sent_qty']])->all();

        return $this->guard(function () use ($transfer, $request, $sentQty) {
            $updated = $this->service->send($transfer, $request->user(), $sentQty);
            $this->broadcast($updated, 'sent');

            return response()->success($this->fresh($updated), 'Transfer sent.');
        });
    }

    /** sent → received | disputed (adds to destination, FEFO batches rebuilt). */
    public function receive(Request $request, Transfer $transfer): JsonResponse
    {
        $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required_with:lines', 'integer'],
            'lines.*.received_qty' => ['required_with:lines', 'numeric', 'gte:0'],
            'dispute_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $receivedQty = collect($request->input('lines', []))
            ->mapWithKeys(fn ($l) => [(int) $l['line_id'] => (float) $l['received_qty']])->all();

        return $this->guard(function () use ($transfer, $request, $receivedQty) {
            $updated = $this->service->receive($transfer, $request->user(), $receivedQty, $request->input('dispute_reason'));
            $this->broadcast($updated, $updated->status->value);

            return response()->success($this->fresh($updated), 'Transfer received.');
        });
    }

    /** disputed → closed_disputed (spawns a corrective transfer for the shortfall). */
    public function resolveDispute(Request $request, Transfer $transfer): JsonResponse
    {
        $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return $this->guard(function () use ($transfer, $request) {
            $updated = $this->service->resolveDispute($transfer, $request->user(), $request->input('notes'));
            $this->broadcast($updated, 'resolved');

            return response()->success($this->fresh($updated), 'Dispute resolved — corrective transfer created.');
        });
    }

    public function cancel(Request $request, Transfer $transfer): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard(function () use ($transfer, $request, $data) {
            $updated = $this->service->cancel($transfer, $request->user(), $data['reason']);
            $this->broadcast($updated, 'cancelled');

            return response()->success($this->fresh($updated), 'Transfer cancelled.');
        });
    }

    private function fresh(Transfer $transfer): TransferResource
    {
        return new TransferResource($transfer->fresh(self::RELATIONS));
    }

    /** Fan out a lightweight change signal so every listening screen refetches. */
    private function broadcast(Transfer $transfer, string $changeType): void
    {
        TransferBroadcastEvent::dispatch(
            $transfer->id,
            $transfer->reference,
            $transfer->status->value,
            $changeType,
        );

        // A transfer raised from a requisition drags that requisition along with
        // it: receiving the last one flips it to `fulfilled`, and its detail
        // screen shows a live "fulfilling transfer" banner. Requisition screens
        // listen on their own channel and hear nothing from a transfer event, so
        // without this they sit on a stale status until a hard refresh — which
        // is exactly how an already-fulfilled requisition kept reading
        // "Approved".
        if ($transfer->requisition_id) {
            $requisition = $transfer->requisition()->first();

            if ($requisition) {
                RequisitionBroadcastEvent::dispatch(
                    $requisition->id,
                    $requisition->reference,
                    $requisition->status->value,
                    'transfer.'.$changeType,
                );
            }
        }
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
