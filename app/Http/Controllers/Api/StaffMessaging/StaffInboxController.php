<?php

namespace App\Http\Controllers\Api\StaffMessaging;

use App\Enums\StaffMessageKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffMessaging\InboxMessageResource;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use App\Services\StaffMessaging\StaffAudienceResolver;
use App\Services\StaffMessaging\StaffMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff member's own inbox.
 *
 * Needs no permission beyond a live staff token — the same shape as submitting
 * feedback. Every route here is scoped to the caller's own recipient rows by
 * construction rather than by a check, so there is no path that could return
 * somebody else's message even if the id were guessed.
 */
class StaffInboxController extends Controller
{
    public function __construct(
        private readonly StaffAudienceResolver $audience,
        private readonly StaffMessageDispatcher $dispatcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->scope($request)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->with(['message.sender'])
            ->latest()
            ->paginate($request->integer('per_page') ?: 20);

        return response()->json([
            'data' => InboxMessageResource::collection($rows->items()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /**
     * The bell, and the interstitial queue, in one call.
     *
     * `pending` carries the full caution bodies rather than just a count, because
     * the interstitial has to be able to render immediately once the till goes
     * idle — a second round trip at that moment would show an empty modal while
     * it loaded.
     */
    public function summary(Request $request): JsonResponse
    {
        $unread = (clone $this->scope($request))->whereNull('read_at')->count();

        // Every kind that takes over the screen, not just cautions — a release
        // walkthrough interrupts too, and reads the same pending set. Asking the
        // enum keeps the two from disagreeing the next time a kind is added.
        $pending = $this->scope($request)
            ->whereNull('acknowledged_at')
            ->whereHas('message', fn ($q) => $q->whereIn('kind', StaffMessageKind::interruptingValues()))
            ->with(['message.sender', 'message.steps'])
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        return response()->success([
            'unread' => $unread,
            'pending' => InboxMessageResource::collection($pending),
        ]);
    }

    public function show(Request $request, StaffMessageRecipient $recipient): JsonResponse
    {
        $this->assertOwn($request, $recipient);

        $recipient->load(['message.sender', 'message.steps', 'message.replies.sender']);

        // Opening it is what reading means. Stamped here rather than by a
        // separate call the client might forget to make.
        $recipient->markRead();

        return response()->success(new InboxMessageResource($recipient));
    }

    /**
     * Acknowledge a caution.
     *
     * Separate from reading on purpose: a glance at the bell should not count as
     * having undertaken anything.
     */
    public function acknowledge(Request $request, StaffMessageRecipient $recipient): JsonResponse
    {
        $this->assertOwn($request, $recipient);

        $recipient->markAcknowledged();

        return response()->success(new InboxMessageResource($recipient->load('message.sender')), 'Got it.');
    }

    /**
     * Reply — a quick reply, free text, or both.
     *
     * `allow_custom_reply` is enforced HERE, not only hidden in the UI. A toggle
     * the server does not honour is decoration.
     */
    public function reply(Request $request, StaffMessageRecipient $recipient): JsonResponse
    {
        $this->assertOwn($request, $recipient);

        $message = $recipient->message;

        $validated = $request->validate([
            'quick_reply' => ['nullable', 'string', 'max:40'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $quick = $validated['quick_reply'] ?? null;
        $body = $validated['body'] ?? null;

        if ($quick === null && ($body === null || trim($body) === '')) {
            return response()->json(['message' => 'Say something first.'], 422);
        }

        if ($quick !== null && ! in_array($quick, $message->quick_replies ?? [], true)) {
            return response()->json([
                'message' => 'That is not one of the offered replies.',
            ], 422);
        }

        if ($body !== null && trim($body) !== '' && ! $message->allow_custom_reply) {
            return response()->json([
                'message' => 'This message only accepts the quick replies offered.',
            ], 422);
        }

        $recipient->forceFill([
            'quick_reply' => $quick ?? $recipient->quick_reply,
            'reply_body' => $body ?: $recipient->reply_body,
            'replied_at' => now(),
            'read_at' => $recipient->read_at ?? now(),
        ])->save();

        return response()->success(new InboxMessageResource($recipient->load('message.sender')), 'Sent.');
    }

    /**
     * Raise something with the IT team, unprompted.
     *
     * The upward direction. Goes to every admin and tech_admin rather than to a
     * named person — a query addressed to somebody on leave would otherwise sit
     * unanswered with the sender assuming it had been seen.
     */
    public function raise(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $team = $this->audience->itTeam();

        if ($team->isEmpty()) {
            return response()->json([
                'message' => 'There is nobody on the IT team to receive this right now.',
            ], 422);
        }

        $message = StaffMessage::create([
            'sender_user_id' => $request->user()->id,
            'kind' => StaffMessageKind::StaffQuery->value,
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'],
            'audience' => ['user_ids' => $team->pluck('id')->all()],
            'allow_custom_reply' => true,
        ]);

        $this->dispatcher->send($message, $team);

        return response()->success(null, 'Sent to the IT team.');
    }

    /** Threads this staff member started, with the replies. */
    public function raised(Request $request): JsonResponse
    {
        $threads = StaffMessage::query()
            ->where('sender_user_id', $request->user()->id)
            ->where('kind', StaffMessageKind::StaffQuery->value)
            ->with(['replies.sender'])
            ->latest()
            ->paginate($request->integer('per_page') ?: 20);

        return response()->json([
            'data' => collect($threads->items())->map(fn (StaffMessage $thread) => [
                'id' => $thread->id,
                'subject' => $thread->subject,
                'body' => $thread->body,
                'sent_at' => $thread->sent_at?->toIso8601String(),
                'replies' => $thread->replies->map(fn ($reply) => [
                    'id' => $reply->id,
                    'body' => $reply->body,
                    'sender_name' => $reply->sender?->name ?? 'CediBites IT',
                    'sent_at' => $reply->sent_at?->toIso8601String(),
                ])->values(),
            ]),
            'meta' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'total' => $threads->total(),
            ],
        ]);
    }

    /**
     * Only this user's rows, and only live messages.
     *
     * Expiry is applied here rather than by a job that stamps rows: the message's
     * own `expires_at` is the single input, and a nightly sweep to set a
     * computable value is a moving part that can fall behind and lie.
     */
    private function scope(Request $request)
    {
        return StaffMessageRecipient::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('message', fn ($q) => $q->live());
    }

    private function assertOwn(Request $request, StaffMessageRecipient $recipient): void
    {
        abort_unless($recipient->user_id === $request->user()->id, 404);
    }
}
