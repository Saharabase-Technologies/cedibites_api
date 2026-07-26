<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Wastage\WastageService;
use App\Enums\Inventory\WastageReason;
use App\Events\Inventory\WastageBroadcastEvent;
use App\Http\Controllers\Api\Inventory\Concerns\SearchesText;
use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\WastageResource;
use App\Models\Inventory\Wastage;
use App\Models\Inventory\WastagePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WastageController extends Controller
{
    use SearchesText;

    private const RELATIONS = [
        'location',
        'disposalLocation',
        'claimantLocation',
        'returnTransfer',
        'lines.item.baseUnit',
        'lines.unit',
        'recordedBy',
        'approvedBy',
        'rejectedBy',
        'cancelledBy',
        'photos.uploadedBy',
    ];

    public function __construct(
        private readonly WastageService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $wastages = Wastage::query()
            ->with(self::RELATIONS)
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('origin'), fn ($q) => $q->where('origin', $request->string('origin')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('recorded_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('recorded_at', '<=', $request->string('date_to')))
            ->when($request->filled('search'), fn ($q) => $q->where('reference', $this->likeOperator(), '%'.$request->string('search').'%'))
            ->latest()
            ->get();

        return response()->success(WastageResource::collection($wastages));
    }

    public function show(Request $request, Wastage $wastage): JsonResponse
    {
        // 404 rather than 403 - an out-of-scope record should not be confirmed
        // to exist.
        abort_unless($wastage->isVisibleTo($request->user()), 404);

        return response()->success(new WastageResource($wastage->load(self::RELATIONS)));
    }

    /** The reason list, so the client never hardcodes a vocabulary that drifts. */
    public function reasons(): JsonResponse
    {
        return response()->success([
            'threshold' => WastageService::threshold(),
            'reasons' => array_map(fn (WastageReason $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'requires_note' => $r->requiresNote(),
            ], WastageReason::selectable()),
        ]);
    }

    /** Declare a wastage. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer', 'exists:inventory_locations,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.reason' => ['required', 'string', Rule::enum(WastageReason::class)],
            'lines.*.reason_note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $wastage = $this->service->record($data, $request->user());
            $this->broadcast($wastage, 'created');

            return response()->success($this->fresh($wastage), match ($wastage->status->value) {
                'pending_return' => 'Recorded. The goods must go back to the warehouse before this can be approved.',
                'pending_approval' => 'Recorded. Awaiting approval from the warehouse manager.',
                default => 'Wastage recorded and written off.',
            });
        });
    }

    /** The approver has seen the goods and agrees the loss is real. */
    public function approve(Request $request, Wastage $wastage): JsonResponse
    {
        return $this->guard(function () use ($wastage, $request) {
            $updated = $this->service->approve($wastage, $request->user());
            $this->broadcast($updated, 'approved');

            return response()->success($this->fresh($updated), 'Wastage approved and written off.');
        });
    }

    public function reject(Request $request, Wastage $wastage): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->guard(function () use ($wastage, $request, $data) {
            $updated = $this->service->reject($wastage, $request->user(), $data['reason']);
            $this->broadcast($updated, 'rejected');

            return response()->success($this->fresh($updated), 'Wastage claim refused - nothing was written off.');
        });
    }

    /**
     * Attach photo evidence. *"So show me the food that has gone bad."*
     *
     * Open to both ends of the claim - the branch making its case and the
     * warehouse inspecting the returned goods - because a disagreement about
     * whether food went bad needs both accounts on the record. The service
     * derives which side you are on; the client does not get to say.
     */
    public function storePhoto(Request $request, Wastage $wastage): JsonResponse
    {
        abort_unless($wastage->isVisibleTo($request->user()), 404);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:10240'], // 10 MB
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->guard(function () use ($request, $wastage) {
            $this->service->attachPhoto(
                $wastage,
                $request->file('photo'),
                $request->user(),
                $request->input('caption'),
            );
            $this->broadcast($wastage, 'evidence');

            return response()->success($this->fresh($wastage), 'Photo added.');
        });
    }

    public function destroyPhoto(Request $request, Wastage $wastage, WastagePhoto $photo): JsonResponse
    {
        abort_unless($wastage->isVisibleTo($request->user()), 404);

        return $this->guard(function () use ($request, $wastage, $photo) {
            $this->service->detachPhoto($wastage, $photo, $request->user());
            $this->broadcast($wastage, 'evidence');

            return response()->success($this->fresh($wastage), 'Photo removed.');
        });
    }

    /** The recorder withdraws their own claim, while nothing has moved yet. */
    public function cancel(Request $request, Wastage $wastage): JsonResponse
    {
        if ((int) $wastage->recorded_by !== (int) $request->user()->id) {
            return response()->forbidden('Only the person who recorded a wastage can withdraw it.');
        }

        return $this->guard(function () use ($wastage, $request) {
            $updated = $this->service->cancel($wastage, $request->user());
            $this->broadcast($updated, 'cancelled');

            return response()->success($this->fresh($updated), 'Wastage withdrawn.');
        });
    }

    private function fresh(Wastage $wastage): WastageResource
    {
        return new WastageResource($wastage->fresh(self::RELATIONS));
    }

    private function broadcast(Wastage $wastage, string $changeType): void
    {
        WastageBroadcastEvent::dispatch(
            $wastage->id,
            $wastage->reference,
            $wastage->status->value,
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
