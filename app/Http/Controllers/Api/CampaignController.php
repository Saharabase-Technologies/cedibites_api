<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Enums\ContactSource;
use App\Enums\DeliveryOutcome;
use App\Enums\GhanaNetwork;
use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignDelivery;
use App\Models\Contact;
use App\Models\MenuItem;
use App\Models\MenuItemOption;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\AudienceRules;
use App\Services\Campaigns\CampaignDeliveryReport;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\MessageMeter;
use App\Services\Contacts\PhoneNormaliser;
use App\Services\HubtelSmsService;
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
        private readonly HubtelSmsService $sms,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::with(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])
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
            (new CampaignResource($campaign->load(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])))->resolve(),
        );
    }

    public function store(SaveCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create([
            ...$request->safe()->only(['name', 'message', 'segment', 'audience_rules', 'short_link_id', 'scheduled_for']),
            'status' => $request->filled('scheduled_for') ? CampaignStatus::Scheduled : CampaignStatus::Draft,
            'created_by_user_id' => $request->user()->id,
        ]);

        $this->stampProjection($campaign);

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

        $campaign->update(
            $request->safe()->only(['name', 'message', 'segment', 'audience_rules', 'short_link_id', 'scheduled_for']),
        );

        $this->stampProjection($campaign);

        return response()->success(
            (new CampaignResource($campaign->fresh(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])))->resolve(),
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
     * Write the reach and cost onto a draft as it is saved.
     *
     * Stamped at save time, not only at send time. Until it was, every draft in
     * the list read "GHS 0.00 projected" — not a small cosmetic problem: the
     * whole point of that list is to show what a campaign will cost before
     * anybody presses send, and zero is the one answer that is never true.
     *
     * Resolved through CampaignSender rather than the resolver directly, so an
     * assembled audience and a preset are counted by exactly the code that will
     * later decide who receives it. Two implementations of "who is in this
     * audience" would eventually disagree, and the draft would promise a
     * different number than the send delivered.
     *
     * These stay a snapshot of a moving target — an audience resolved last week
     * is not the audience being sent to today — which is why the sender resolves
     * it again and overwrites both figures at send time. The draft is the shop
     * window; the send is the till.
     */
    private function stampProjection(Campaign $campaign): void
    {
        $recipients = $this->sender->audienceSize($campaign);

        $campaign->update([
            'recipient_count' => $recipients,
            'segments_per_message' => $this->meter->segments($campaign->message),
            'estimated_cost' => $this->meter->estimateCost($campaign->message, $recipients),
        ]);
    }

    /**
     * Who received it and who did not, one recipient at a time.
     *
     * The reason this exists: a campaign that reports "3,812 of 4,000 delivered"
     * tells you there is a problem and nothing about what to do. The 188 split
     * into dead numbers and handsets that were switched off call for opposite
     * responses — retire the first, try the second again tomorrow — and only a
     * list can tell them apart.
     */
    public function deliveries(Request $request, Campaign $campaign, CampaignDeliveryReport $report): JsonResponse
    {
        $outcome = $request->string('outcome')->toString();

        $deliveries = CampaignDelivery::where('campaign_id', $campaign->id)
            // `not_delivered` is the default view worth having: it is the only
            // list anybody opens this screen to see.
            ->when($outcome === 'not_delivered', fn ($q) => $q->notDelivered())
            ->when($outcome !== '' && $outcome !== 'not_delivered',
                fn ($q) => $q->where('outcome', $outcome))
            ->orderBy('outcome')
            ->orderBy('phone')
            ->paginate($request->integer('per_page', 50));

        return response()->success([
            'summary' => $report->summarise($campaign),
            'curve' => $report->curve($campaign),
            'deliveries' => $deliveries->toArray(),
            'outcomes' => array_map(fn (DeliveryOutcome $o) => [
                'value' => $o->value,
                'label' => $o->label(),
                'description' => $o->description(),
            ], DeliveryOutcome::cases()),
        ]);
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
     * One copy of this campaign, to one number, now.
     *
     * The thing you do before you press send. It texts `$campaign->message`
     * character for character, which is the only way to find out what the
     * message actually looks like on a handset: whether the short link survived
     * the paste, whether a curly quote off Word has quietly turned 160
     * characters into 70, whether the line breaks land where they were written.
     * Reading it in the composer proves none of that.
     *
     * Deliberately exempt from three rails, for the same reason
     * DirectMessageController is exempt from two:
     *
     *   Seed mode — would redirect the test to the staff list instead of the
     *   number that was typed, which is the one outcome a test must never
     *   produce. You would be shown somebody else's phone as proof.
     *
     *   The send window — a test is one text to a colleague standing next to
     *   you, not 28,000 landing at six in the morning.
     *
     *   The recipient cap — it counts an audience, and this has an audience of
     *   one. (There is no cap by default now anyway; see config/campaigns.php.)
     *
     * What it does NOT touch is every counter on the campaign. The status stays
     * draft, sent_count stays where it was, no cost is recorded and no batch id
     * is kept. A campaign that has been tested is a campaign that has not been
     * sent, and the confirm screen must still show a first send as a first send.
     */
    public function test(Request $request, Campaign $campaign): JsonResponse
    {
        // Testing a campaign that has already gone out tells you nothing you
        // cannot read on the report, and spends money to say it.
        if (! $campaign->status->isEditable()) {
            return response()->unprocessable(
                'This campaign has already gone out. There is nothing left to test.'
            );
        }

        if (trim((string) $campaign->message) === '') {
            return response()->unprocessable('There is no message to test yet.');
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ], [
            'phone.required' => 'Which number should the test go to?',
        ]);

        // The same reader the importer and the direct sender use, so 0241234567,
        // +233241234567 and a number pasted out of WhatsApp all reach the same
        // handset. Anything that is not a Ghana mobile is refused here rather
        // than by Hubtel after we have been billed for the attempt.
        $phone = PhoneNormaliser::normalise($validated['phone']);

        if ($phone === null) {
            return response()->unprocessable(
                'That is not a Ghana mobile number. It should look like 0241234567.'
            );
        }

        $measurement = $this->meter->measure($campaign->message);

        try {
            $this->sms->sendSingle(
                PhoneHelper::toInternational($phone),
                $campaign->message,
                'campaign_test',
            );
        } catch (\Throwable $e) {
            activity('admin')
                ->causedBy($request->user())
                ->performedOn($campaign)
                ->event('campaign_test_failed')
                ->withProperties(['phone' => $phone, 'error' => $e->getMessage()])
                ->log('Campaign test failed to '.$phone);

            return response()->unprocessable('That test could not be sent: '.$e->getMessage());
        }

        // Written straight to the row rather than through save(), so the model's
        // activity log does not record a test as an edit to the campaign. The
        // test has its own log line below.
        $campaign->forceFill([
            'last_tested_at' => now(),
            'last_tested_phone' => $phone,
            'last_tested_by_user_id' => $request->user()->id,
        ])->saveQuietly();

        activity('admin')
            ->causedBy($request->user())
            ->performedOn($campaign)
            ->event('campaign_tested')
            ->withProperties([
                'phone' => $phone,
                // The text as it was at the moment of the test. A campaign edited
                // afterwards is a campaign nobody has actually seen on a phone,
                // and this is what shows that.
                'message' => $campaign->message,
                'segments' => $measurement['segments'],
                'cost' => $this->meter->estimateCost($campaign->message, 1),
            ])
            ->log('Campaign "'.$campaign->name.'" tested to '.$phone);

        return response()->success(
            (new CampaignResource($campaign->fresh(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])))->resolve(),
            'Test sent to '.$phone.'. Check the handset before you send this to anybody else.',
        );
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
            (new CampaignResource($campaign->load(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])))->resolve(),
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
            (new CampaignResource($campaign->fresh(['createdBy', 'approvedBy', 'shortLink', 'lastTestedBy'])))->resolve(),
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

            /*
             * The rate the projection is built from.
             *
             * Served rather than duplicated in the frontend. It was hard-coded
             * there as 0.05 while this config said 0.0243, so the composer
             * quoted double what the confirm dialog did for the same message —
             * two screens, two answers, both wrong to somebody.
             */
            'rate_per_segment' => (float) config('campaigns.estimated_rate_per_segment', 0.0243),
        ]);
    }

    /**
     * How many people a set of rules matches, right now.
     *
     * Called as the operator builds the audience, so the count moves as rules
     * are added. It is the only honest way to build one: "Lapsed MTN customers
     * who bought jollof" is a sentence, and it means nothing until it says 312
     * beside it.
     *
     * A resolve, not an estimate. The same code answers this and decides who
     * actually receives the campaign, so the number shown here is the number
     * that will be sent to.
     */
    public function countAudience(Request $request): JsonResponse
    {
        $validated = $request->validate(SaveCampaignRequest::audienceRules());

        $rules = AudienceRules::fromArray($validated['audience_rules'] ?? []);

        return response()->success([
            'count' => $this->audience->countRules($rules),
            // Read back so the review step and the audit trail can say what was
            // asked for, not just how many it found.
            'description' => $rules->describe(),
        ]);
    }

    /**
     * Everything the audience builder can filter on.
     *
     * Served rather than hard-coded in the frontend so the dish list and the
     * branch list cannot go stale, and so the networks stay in step with
     * GhanaNetwork.
     */
    public function audienceOptions(): JsonResponse
    {
        return response()->success([
            'branches' => Branch::orderBy('name')->get(['id', 'name'])
                ->map(fn (Branch $b) => ['value' => $b->id, 'label' => $b->name]),

            /*
             * The sellable lines — what a customer actually bought and what
             * appears on the receipt.
             *
             * This is the list that matters. Targeting by menu item alone was
             * asking "did they ever buy Jollof?" when the useful question is
             * "did they buy the Large?" — a different dish at a different price
             * to a different person.
             *
             * Labelled with the item name so an option never reads as a bare
             * "Large" with nothing to attach it to, and grouped so the picker
             * can nest them.
             */
            'menu_item_options' => MenuItemOption::query()
                ->whereHas('menuItem', fn ($q) => $q->whereNull('deleted_at'))
                ->with('menuItem:id,name')
                // display_order is selected because it is sorted on below —
                // omitting it would silently read null for every row and leave
                // the options in database order.
                ->get(['id', 'menu_item_id', 'option_label', 'display_name', 'display_order'])
                ->sortBy(fn (MenuItemOption $o) => [$o->menuItem?->name, $o->display_order])
                ->values()
                ->map(fn (MenuItemOption $o) => [
                    'value' => $o->id,
                    // Same fallback the recipe screens use, so one option is not
                    // named two different things in two places.
                    'label' => $o->display_name ?: trim(($o->menuItem?->name ?? '').' — '.$o->option_label),
                    'group' => $o->menuItem?->name,
                ]),

            'menu_items' => MenuItem::whereNull('deleted_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn (MenuItem $m) => ['value' => $m->id, 'label' => $m->name]),

            'networks' => array_map(fn (GhanaNetwork $n) => [
                'value' => $n->value,
                'label' => $n->label(),
            ], GhanaNetwork::cases()),

            /*
             * The two pools, with a live headcount each.
             *
             * They are a partition, so these two figures do not overlap and can
             * honestly be added. The supplementary count is contacts who have
             * never ordered — NOT the size of the contact base. A converted
             * contact is already counted on the customers side, and showing the
             * raw total here would have the operator adding two numbers that
             * share people.
             */
            'sources' => array_map(fn (ContactSource $s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'description' => $s->description(),
                'count' => $s === ContactSource::Supplementary
                    ? Contact::unconverted()->count()
                    : $this->audience->count(CampaignSegment::All),
            ], ContactSource::cases()),
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
