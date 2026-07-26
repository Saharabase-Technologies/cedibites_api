<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Uploads\UploadSessionException;
use App\Services\Uploads\UploadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The phone half of phone-as-camera. **Outside auth middleware.**
 *
 * A person standing over a crate of spoiled food scans a code and lands here.
 * They are not logged in and will not be. The token in the URL is the entire
 * credential, and it is a credential anyone who glanced at the laptop could be
 * holding, so this controller is written to be safe in the hands of a stranger:
 *
 *   - upload-only. There is no endpoint here that returns document contents.
 *   - `show()` returns a reference and one line of instruction. Nothing else.
 *   - every request is attributed (IP + user agent) whether it succeeds or not.
 *   - a bad token and an expired token give the same shape of answer, so this
 *     never becomes an oracle for which tokens exist.
 *
 * Throttled per token and per IP at the route. See routes/uploads.php.
 */
class PublicUploadController extends Controller
{
    public function __construct(
        private readonly UploadSessionService $sessions,
    ) {}

    /**
     * What the phone shows on arrival: which document, and what to do.
     *
     * Deliberately thin. The reference is enough to confirm you are at the
     * right crate; the label says to photograph it. Quantities, values,
     * locations and who is disputing what stay on the laptop.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        try {
            $session = $this->sessions->resolve($token);
        } catch (UploadSessionException $e) {
            return response()->error($e->getMessage(), 404);
        }

        $handler = $this->sessions->handler($session);
        $this->sessions->touch($session, $request);

        return response()->success([
            'reference' => $handler->reference($session->attachable),
            'label' => $handler->label($session->attachable),
            'expires_at' => $session->expires_at->toIso8601String(),
            'expires_in_seconds' => max(0, (int) now()->diffInSeconds($session->expires_at, false)),
            'files_uploaded' => $session->files_uploaded,
            'max_files' => $session->max_files,
            'remaining' => max(0, $session->max_files - $session->files_uploaded),

            // So the phone can size-check before spending a minute of 3G
            // uploading something the server is going to refuse.
            'accepts' => [
                'image_mimetypes' => array_values((array) config('upload-sessions.image_mimetypes')),
                'video_mimetypes' => array_values((array) config('upload-sessions.video_mimetypes')),
                'max_image_bytes' => (int) config('upload-sessions.max_image_kb') * 1024,
                'max_video_bytes' => (int) config('upload-sessions.max_video_kb') * 1024,
            ],
        ]);
    }

    /**
     * The upload itself. One file per request.
     *
     * One at a time on purpose: a phone on mobile data loses a 40 MB multi-file
     * POST at 90% and has nothing to show for it, whereas three separate
     * requests lose only the one that failed and the page can retry it alone.
     */
    public function store(Request $request, string $token): JsonResponse
    {
        try {
            $session = $this->sessions->resolve($token);
        } catch (UploadSessionException $e) {
            return response()->error($e->getMessage(), 404);
        }

        $handler = $this->sessions->handler($session);

        // Attribute the attempt before validating it. A token being hammered
        // with rejected files is exactly the trail worth having.
        $this->sessions->touch($session, $request);

        try {
            $request->validate([
                'file' => $handler->fileRules(),
                'caption' => ['nullable', 'string', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            // 422 with the rule's own wording - these messages are written to
            // be read by someone holding a phone in a store room.
            return response()->error(
                $e->validator->errors()->first() ?: 'That file could not be accepted.',
                422
            );
        }

        // The session acts AS the person who generated the QR at the laptop.
        // Not decoration: wastage derives `stage` from the actor, so an
        // anonymous upload would file the branch's evidence as the approver's.
        $actor = $session->createdBy;

        if ($actor === null) {
            return response()->error('The account this link belongs to is no longer active.', 422);
        }

        try {
            $handler->handle(
                $session->attachable,
                $request->file('file'),
                $actor,
                $request->input('caption'),
                $session,
            );
        } catch (UploadSessionException $e) {
            return response()->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            // Never leak an internal message to an unauthenticated caller.
            Log::error('Upload session file failed.', [
                'session_id' => $session->id,
                'purpose' => $session->purpose,
                'exception' => $e->getMessage(),
            ]);

            return response()->error('That file could not be saved. Try again.', 422);
        }

        $this->sessions->recordUpload($session, $request);

        return response()->success([
            'files_uploaded' => $session->files_uploaded,
            'max_files' => $session->max_files,
            'remaining' => max(0, $session->max_files - $session->files_uploaded),
        ], 'Sent. It is on the computer now.');
    }
}
