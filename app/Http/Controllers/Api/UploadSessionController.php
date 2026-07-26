<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadSession;
use App\Services\Uploads\UploadSessionException;
use App\Services\Uploads\UploadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The desktop half of phone-as-camera: mint a QR, or cancel one.
 *
 * Authenticated, because the token that comes out of here acts as the caller.
 * The public half a phone talks to is PublicUploadController.
 */
class UploadSessionController extends Controller
{
    /**
     * Which models may be targeted, keyed by the short name a client sends.
     *
     * A whitelist rather than accepting a class name over the wire: an
     * attacker-supplied `attachable_type` would otherwise be a way to point a
     * morph at any model in the application.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const TARGETS = [
        'wastage' => \App\Models\Inventory\Wastage::class,
    ];

    public function __construct(
        private readonly UploadSessionService $sessions,
    ) {}

    /**
     * Mint a token and hand back the URL to draw as a QR code.
     *
     * The raw token is in this response and nowhere else, ever again. If the
     * user closes the dialog before scanning, they press the button again and
     * get a new one - which also invalidates the old.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', 'string', Rule::in(array_keys(self::TARGETS))],
            'target_id' => ['required', 'integer'],
            'purpose' => ['required', 'string', Rule::in(array_keys((array) config('upload-sessions.handlers')))],
        ]);

        $model = self::TARGETS[$data['target_type']];
        $target = $model::find($data['target_id']);

        // 404 rather than 403 - authorization for the target is the handler's
        // job (`canIssue`), and a record the caller cannot reach should not be
        // confirmed to exist.
        if ($target === null) {
            return response()->error('That record could not be found.', 404);
        }

        try {
            $issued = $this->sessions->issue(
                $target,
                $request->user(),
                $data['purpose'],
                ttlMinutes: (int) config('upload-sessions.ttl_minutes'),
                maxFiles: (int) config('upload-sessions.max_files'),
            );
        } catch (UploadSessionException $e) {
            return response()->error($e->getMessage(), 422);
        }

        /** @var UploadSession $session */
        $session = $issued['session'];

        return response()->success([
            'id' => $session->id,
            'url' => $issued['url'],
            'expires_at' => $session->expires_at->toIso8601String(),
            'expires_in_seconds' => max(0, (int) now()->diffInSeconds($session->expires_at, false)),
            'max_files' => $session->max_files,
        ], 'Scan the code with a phone.');
    }

    /**
     * How a session is doing, so the desktop can show "2 files received" and
     * stop drawing a code that has expired. Deliberately not the file list -
     * the document itself already broadcasts and carries that.
     */
    public function show(Request $request, UploadSession $uploadSession): JsonResponse
    {
        abort_unless($this->ownedBy($uploadSession, $request), 404);

        return response()->success([
            'id' => $uploadSession->id,
            'expires_at' => $uploadSession->expires_at->toIso8601String(),
            'expires_in_seconds' => max(0, (int) now()->diffInSeconds($uploadSession->expires_at, false)),
            'files_uploaded' => $uploadSession->files_uploaded,
            'max_files' => $uploadSession->max_files,
            'usable' => $uploadSession->isUsable(),
            'last_used_at' => optional($uploadSession->last_used_at)->toIso8601String(),
        ]);
    }

    /**
     * Kill it early. The reason this exists: someone realises the screen with
     * the code on it was visible to a room, and wants the credential dead now
     * rather than in nine minutes.
     */
    public function destroy(Request $request, UploadSession $uploadSession): JsonResponse
    {
        abort_unless($this->ownedBy($uploadSession, $request), 404);

        $this->sessions->revoke($uploadSession);

        return response()->success(null, 'That code will no longer work.');
    }

    /**
     * Only the person who minted a session may inspect or cancel it.
     *
     * Not "anyone who can see the document": the session acts AS its creator,
     * so it is their credential to manage. A colleague who wants it gone can
     * settle the document, which closes every session on it.
     */
    private function ownedBy(UploadSession $session, Request $request): bool
    {
        return (int) $session->created_by === (int) $request->user()->id;
    }
}
