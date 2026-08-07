<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\MessageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The campaign console — what replaces logging into the Hubtel dashboard.
 *
 * Composing and sending are two separate calls on purpose. A campaign is inert
 * until `send` is called, and `send` shows the operator the recipient count, the
 * character count, the billed segments and the projected cost before it will do
 * anything. The rails that make that safe live in CampaignSender, not here,
 * because a scheduled send never passes through a controller.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignSender $sender,
        private readonly AudienceResolver $audience,
        private readonly MessageMeter $meter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::with(['createdBy', 'approvedBy', 'shortLink'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->success(
            CampaignResource::collection($campaigns)->response()->getData(true),
        );
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return response()->success(
            (new CampaignResource($campaign->load(['createdBy', 'approvedBy', 'shortLink'])))->resolve(),
        );
    }

    public function store(SaveCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create([
            ...$request->safe()->only(['name', 'message', 'segment', 'short_link_id', 'scheduled_for']),
            'status' => $request->filled('scheduled_for') ? CampaignStatus::Scheduled : CampaignStatus::Draft,
            'created_by_user_id' => $request->user()->id,
        ]);

        return response()->created(
            (new CampaignResource($campaign->load(['createdBy', 'shortLink'])))->resolve(),
        );
    }

    /**
     * Edit a campaign that has not gone out.
     *
     * Refused once sending has started. There is nothing to undo at that point —
     * Hubtel has the chunks — and letting the text change afterwards would make
     * the activity log a record of something nobody sent.
     */
    public function update(SaveCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        if (! $campaign->status->isEditable()) {
            return response()->unprocessable('This campaign has already gone out. Copy it into a new one instead.');
        }

        $campaign->update($request->safe()->only([
            'name', 'message', 'segment', 'short_link_id', 'scheduled_for',
        ]));

        return response()->success(
            (new CampaignResource($campaign->fresh(['createdBy', 'approvedBy', 'shortLink'])))->resolve(),
            'Campaign saved.',
        );
    }

    /**
     * Delete a campaign that never sent.
     *
     * A campaign that did send is history, and history is what the reporting is
     * for. Those are cancelled or simply left alone, never deleted.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->status->hasStarted()) {
            return response()->unprocessable(
                'This campaign has already gone out, and its figures are part of the record. It cannot be deleted.'
            );
        }

        $campaign->delete();

        return response()->deleted();
    }

    /**
     * What this campaign would cost and reach, without sending anything.
     *
     * This is the confirm step. Every figure on it is resolved live, so the
     * recipient count is what the send will actually use rather than what the
     * segment held when the draft was written.
     */
    public function preview(Campaign $campaign): JsonResponse
    {
        return response()->success($this->sender->preview($campaign));
    }

    /**
     * Send it. The only call here that spends money.
     */
    public function send(Request $request, Campaign $campaign): JsonResponse
    {
        try {
            $campaign = $this->sender->send($campaign, $request->user());
        } catch (RuntimeException $e) {
            // Every rail in CampaignSender refuses this way — over the cap,
            // outside the send window, an empty segment, or already sent.
            return response()->unprocessable($e->getMessage());
        }

        return response()->success(
            (new CampaignResource($campaign->load(['createdBy', 'approvedBy', 'shortLink'])))->resolve(),
            $this->sender->seedMode()
                ? 'Sent to the test list. Seed mode is on, so no customers were messaged.'
                : 'Campaign sending.',
        );
    }

    /**
     * Stop a scheduled campaign before it goes.
     *
     * Only works before the first chunk is queued. Once Hubtel has accepted a
     * batch there is nothing on our side to recall.
     */
    public function cancel(Campaign $campaign): JsonResponse
    {
        if (! $campaign->status->isEditable()) {
            return response()->unprocessable('This campaign has already started. There is nothing left to cancel.');
        }

        $campaign->update(['status' => CampaignStatus::Cancelled]);

        return response()->success(
            (new CampaignResource($campaign->fresh(['createdBy', 'approvedBy', 'shortLink'])))->resolve(),
            'Campaign cancelled.',
        );
    }

    /**
     * The segments, with a live headcount for each.
     *
     * Counted rather than described, because "churned" means nothing to an
     * operator until it says 4,812 people next to it.
     */
    public function segments(): JsonResponse
    {
        return response()->success([
            'segments' => array_map(fn (CampaignSegment $segment) => [
                'value' => $segment->value,
                'label' => $segment->label(),
                'description' => $segment->description(),
                'count' => $this->audience->count($segment),
            ], CampaignSegment::cases()),

            // Surfaced so the composer can say plainly that nothing is reaching
            // customers yet, rather than letting somebody discover it after a
            // demo.
            'seed_mode' => $this->sender->seedMode(),
            'recipient_cap' => (int) config('campaigns.recipient_cap', 2000),
        ]);
    }

    /**
     * Measure a message without saving anything.
     *
     * The frontend has its own copy of this for the live counter; this endpoint
     * is the authority the confirm step checks against, so the two cannot
     * disagree about what a campaign costs.
     */
    public function measure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1600'],
            'recipients' => ['sometimes', 'integer', 'min:0'],
        ]);

        $measurement = $this->meter->measure($validated['message']);
        $recipients = (int) ($validated['recipients'] ?? 0);

        return response()->success([
            ...$measurement,
            'estimated_cost' => $this->meter->estimateCost($validated['message'], $recipients),
        ]);
    }
}
