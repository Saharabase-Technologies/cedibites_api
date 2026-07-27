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
use App\Models\UploadSession;
use App\Rules\EvidenceMedia;
use App\Services\Uploads\UploadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            // Photos the phone sent while this form was still being filled in.
            'upload_session_id' => ['sometimes', 'integer'],
        ]);

        return $this->guard(function () use ($data, $request) {
            $wastage = $this->service->record($data, $request->user());

            // Attach anything staged before the claim existed. Never allowed to
            // fail the claim: it is saved, and the detail page can always take
            // more photos.
            if (! empty($data['upload_session_id'])) {
                $session = UploadSession::find($data['upload_session_id']);
                if ($session !== null) {
                    try {
                        app(UploadSessionService::class)->claim($session, $wastage, $request->user());
                    } catch (\Throwable $e) {
                        Log::warning('Could not attach staged photos to a new wastage claim.', [
                            'wastage_id' => $wastage->id,
                            'session_id' => $session->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $this->broadcast($wastage, 'created');

            return response()->success($this->fresh($wastage), match ($wastage->status->value) {
                'pending_return' => 'Recorded. The goods must go back to the warehouse before this can be approved.',
                'pending_approval' => 'Recorded. Awaiting approval from the warehouse manager.',
                default => 'Wastage recorded and written off.',
            });
        });
    }

    /**
     * Sign off a claim, in whole or in part.
     *
     * `approved_qty` is line_id => quantity allowed; anything omitted is allowed
     * in full, so an unchanged client keeps behaving exactly as before. Partial
     * approval is the point of returning the goods at all - 20 kg comes back,
     * 10 kg turns out to be fine, and neither "write off everything" nor "call
     * it a lie" is the truth.
     */
    public function approve(Request $request, Wastage $wastage): JsonResponse
    {
        $data = $request->validate([
            'approved_qty' => ['sometimes', 'array'],
            'approved_qty.*' => ['numeric', 'min:0'],
        ]);

        return $this->guard(function () use ($wastage, $request, $data) {
            $granted = collect($data['approved_qty'] ?? [])
                ->mapWithKeys(fn ($qty, $lineId) => [(int) $lineId => (float) $qty])
                ->all();

            $updated = $this->service->approve($wastage, $request->user(), $granted);
            $this->broadcast($updated, 'approved');

            $partial = $updated->lines->contains(
                fn ($l) => $l->approved_qty !== null && (float) $l->approved_qty < (float) $l->quantity
            );

            return response()->success(
                $this->fresh($updated),
                $partial
                    ? 'Approved in part - only what you allowed has been written off.'
                    : 'Wastage approved and written off.'
            );
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

        // The same rule the phone endpoint uses, so a clip the laptop accepts is
        // never refused by the QR page or the reverse. It covers video as well
        // as stills: a still of a crate proves it is full, a ten-second pan
        // proves the smell argument in a way a still cannot.
        $request->validate([
            'photo' => ['required', 'file', new EvidenceMedia],
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
