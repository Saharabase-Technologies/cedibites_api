<?php

namespace App\Http\Controllers\Api\StaffMessaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffMessaging\SendStaffMessageRequest;
use App\Http\Resources\StaffMessaging\StaffMessageResource;
use App\Models\StaffMessage;
use App\Services\StaffMessaging\StaffAudienceResolver;
use App\Services\StaffMessaging\StaffMessageDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The admin's side: compose, send, and read the receipts.
 *
 * Gated on `staff_messages.manage`, which only admin and tech_admin hold.
 * Managers deliberately do not send — decided 2026-08-17.
 */
class StaffMessageController extends Controller
{
    public function __construct(
        private readonly StaffAudienceResolver $audience,
        private readonly StaffMessageDispatcher $dispatcher,
    ) {}

    /** Everything sent, newest first. Includes what the rules sent. */
    public function index(Request $request): JsonResponse
    {
        $messages = StaffMessage::query()
            ->with('sender')
            ->withCount('recipients')
            // Replies are shown inside their parent thread, not as top-level
            // entries — otherwise a busy conversation buries every broadcast.
            ->whereNull('parent_id')
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when(
                $request->boolean('automatic_only'),
                fn ($q) => $q->whereNull('sender_user_id'),
            )
            ->when(
                $request->boolean('manual_only'),
                fn ($q) => $q->whereNotNull('sender_user_id'),
            )
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->paginate($request->integer('per_page') ?: 20);

        return response()->json([
            'data' => StaffMessageResource::collection($messages->items()),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /**
     * How many people an audience currently reaches.
     *
     * Its own endpoint so the compose form can show the number BEFORE the send
     * button is pressed. "This goes to 41 people" is the last chance anybody has
     * to notice they have selected the whole company.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['audience' => ['required', 'array']]);

        $recipients = $this->audience->resolve((array) $request->input('audience'));

        return response()->success([
            'count' => $recipients->count(),
            'sample' => $recipients->take(8)->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->getRoleNames()->first(),
            ])->values(),
        ]);
    }

    /**
     * Take an image and hand back the path to attach it to a message.
     *
     * Separate from the send so the compose form can show a preview before
     * committing. The cost is that abandoning a compose leaves an orphaned file;
     * that is a cheap, sweepable problem, whereas the alternative — trusting a
     * URL the client supplies — is a permanent hole through which any message
     * could be made to render somebody else's image inside our chrome.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            // Explicit types rather than the `image` rule alone: that accepts
            // SVG, which is a script-execution vector when served from our own
            // origin.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $path = $request->file('image')->store('staff-messages', 'public');

        return response()->success([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    public function store(SendStaffMessageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $recipients = $this->audience->resolve($data['audience']);

        if ($recipients->isEmpty()) {
            // A 422 rather than an empty success. Silently accepting a send that
            // reached nobody means the sender believes the message went out.
            return response()->json([
                'message' => 'That audience matches nobody right now.',
                'errors' => ['audience' => ['No active staff match this selection.']],
            ], 422);
        }

        $message = StaffMessage::create([
            'sender_user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'image_path' => $data['image_path'] ?? null,
            'audience' => $data['audience'],
            'requires_acknowledgement' => $data['requires_acknowledgement'] ?? false,
            'allow_custom_reply' => $data['allow_custom_reply'] ?? true,
            'quick_replies' => $data['quick_replies'] ?? null,
            'sms_fallback_after_minutes' => $data['sms_fallback_after_minutes'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        $this->dispatcher->send($message, $recipients);

        // Sending to staff is an act worth a permanent record, separate from the
        // message rows themselves — those are deletable.
        Log::channel('stack')->info('Staff message sent', [
            'message_id' => $message->id,
            'sender_id' => $request->user()->id,
            'kind' => $message->kind->value,
            'recipients' => $recipients->count(),
        ]);

        return response()->success(
            new StaffMessageResource($message->load('sender', 'recipients.user', 'recipients.branch')),
            "Sent to {$recipients->count()} ".($recipients->count() === 1 ? 'person' : 'people').'.',
        );
    }

    /** One message with every receipt — who read it, who acknowledged, who replied. */
    public function show(StaffMessage $staffMessage): JsonResponse
    {
        return response()->success(
            new StaffMessageResource(
                $staffMessage->load('sender', 'recipients.user', 'recipients.branch', 'replies.sender')
            ),
        );
    }

    /**
     * Reply into a thread — the admin's side of a staff query.
     *
     * Creates a child message addressed to whoever started the thread, so it
     * lands in their inbox with the same delivery ladder as anything else.
     */
    public function reply(Request $request, StaffMessage $staffMessage): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $originator = $staffMessage->sender;

        if (! $originator) {
            return response()->json([
                'message' => 'This message has nobody to reply to.',
            ], 422);
        }

        $reply = StaffMessage::create([
            'sender_user_id' => $request->user()->id,
            'parent_id' => $staffMessage->id,
            'kind' => \App\Enums\StaffMessageKind::Direct->value,
            'subject' => $staffMessage->subject ? 'Re: '.$staffMessage->subject : null,
            'body' => $validated['body'],
            'audience' => ['user_ids' => [$originator->id]],
            'allow_custom_reply' => true,
        ]);

        $this->dispatcher->send($reply, collect([$originator]));

        return response()->success(new StaffMessageResource($reply->load('sender')), 'Reply sent.');
    }

    /**
     * Withdraw a message.
     *
     * Soft delete, and the recipient rows go with it via cascade on hard delete
     * only — so the receipts survive. Somebody having already read a caution is
     * a fact that withdrawing the message does not undo.
     */
    public function destroy(StaffMessage $staffMessage): JsonResponse
    {
        $staffMessage->delete();

        return response()->success(null, 'Message withdrawn.');
    }
}
