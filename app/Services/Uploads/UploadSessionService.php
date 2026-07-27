<?php

namespace App\Services\Uploads;

use App\Models\UploadSession;
use App\Models\UploadSessionFile;
use App\Models\User;
use App\Services\Uploads\Contracts\UploadSessionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issues and redeems phone-as-camera upload sessions.
 *
 * The desktop calls `issue()` and draws the returned URL as a QR code. A phone
 * scans it and hits the public endpoints, which go through `resolve()`.
 *
 * The one rule that governs this whole file: **the raw token exists in exactly
 * two places - the QR code on the screen, and the URL bar of whatever phone
 * scanned it.** It is never stored, never logged, never returned twice. If the
 * user closes the QR before scanning, the token is gone and they press the
 * button again.
 */
class UploadSessionService
{
    /**
     * Long enough that guessing is not a strategy, short enough that the QR
     * stays low-density and scans on a cheap camera in a dim store room.
     */
    private const TOKEN_BYTES = 32;

    public const DEFAULT_TTL_MINUTES = 10;

    public const DEFAULT_MAX_FILES = 10;

    /**
     * Mint a token for one document.
     *
     * Any live session the same person already holds on the same document is
     * revoked first. Two consequences, both wanted: at most one live token per
     * person per document, and pressing "show QR" again invalidates whatever a
     * passer-by photographed off the screen a minute ago.
     *
     * Scoped to `created_by` rather than the document, because the branch clerk
     * and the warehouse manager may legitimately hold sessions on the same
     * claim at once - and they upload as different `stage`s.
     *
     * @return array{session: UploadSession, token: string, url: string}
     */
    public function issue(
        ?Model $target,
        User $actor,
        string $purpose,
        ?int $maxFiles = null,
        ?int $ttlMinutes = null,
    ): array {
        $handler = $this->handler($purpose);

        /*
         * A STAGED session - no target. Minted by a form that has not saved
         * anything yet, so the photograph can be taken at the crate while the
         * notes and the second item are still being typed. Files wait on the
         * session until the document exists and `claim()` attaches them.
         */
        if ($target === null) {
            if (! $handler->canStage($actor)) {
                throw new UploadSessionException('You cannot start an upload for this kind of record.');
            }
        } elseif (! $handler->canIssue($target, $actor)) {
            throw new UploadSessionException(
                'This record can no longer take photos, so there is nothing to upload to.'
            );
        }

        $token = Str::random(self::TOKEN_BYTES);

        $session = DB::transaction(function () use ($target, $actor, $purpose, $maxFiles, $ttlMinutes, $token) {
            // Only for a real document. Two staged sessions can legitimately
            // be open at once - two forms in two tabs - and they have no
            // document in common to collide over.
            if ($target !== null) {
                UploadSession::query()
                    ->for($target)
                    ->where('created_by', $actor->id)
                    ->usable()
                    ->update(['revoked_at' => now()]);
            }

            return UploadSession::create([
                'token_hash' => $this->hash($token),
                'attachable_type' => $target?->getMorphClass(),
                'attachable_id' => $target?->getKey(),
                'created_by' => $actor->id,
                'purpose' => $purpose,
                'max_files' => $maxFiles ?? self::DEFAULT_MAX_FILES,
                'expires_at' => now()->addMinutes($ttlMinutes ?? self::DEFAULT_TTL_MINUTES),
            ]);
        });

        return [
            'session' => $session,
            'token' => $token,
            'url' => $this->url($token),
        ];
    }

    /**
     * Look up a session by raw token and confirm it may still be used.
     *
     * Throws with a message safe to render on a phone: an unknown token and an
     * expired one give the same shape of answer, because the endpoint is
     * reachable by anyone and must not become an oracle for which tokens exist.
     */
    public function resolve(string $token): UploadSession
    {
        $session = UploadSession::query()
            ->where('token_hash', $this->hash($token))
            ->with('attachable')
            ->first();

        if ($session === null) {
            throw new UploadSessionException(
                'This link is not valid. Show the QR code again on the computer to get a new one.'
            );
        }

        if ($reason = $session->unusableReason()) {
            throw new UploadSessionException($reason);
        }

        // A session whose document was deleted underneath it. A staged session
        // legitimately has none yet, which is the whole point of staging.
        if (! $session->isStaging() && $session->attachable === null) {
            throw new UploadSessionException('The record this link belonged to no longer exists.');
        }

        return $session;
    }

    /** The handler that gives a `purpose` its meaning. */
    public function handler(UploadSession|string $purpose): UploadSessionHandler
    {
        $key = $purpose instanceof UploadSession ? $purpose->purpose : $purpose;
        $class = config("upload-sessions.handlers.{$key}");

        if ($class === null) {
            // A purpose with no handler is a deployment mistake, not user input.
            Log::error('Upload session purpose has no handler.', ['purpose' => $key]);

            throw new UploadSessionException('This kind of upload is not available.');
        }

        return app($class);
    }

    /**
     * Record that the token was used to look at the page. Attribution for a
     * credential that anyone who saw the screen could be holding - if evidence
     * later turns out to have been planted, this is the trail.
     */
    public function touch(UploadSession $session, Request $request): void
    {
        $session->forceFill([
            'last_used_at' => now(),
            'last_ip' => $request->ip(),
            'last_user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ])->save();
    }

    /**
     * Count a successful upload against the session's budget.
     *
     * `increment` rather than read-modify-write: a phone on a flaky 3G
     * connection retries, and two requests landing together must not both read
     * the same count and let one file through over the cap.
     */
    public function recordUpload(UploadSession $session, Request $request): void
    {
        $session->increment('files_uploaded');
        $this->touch($session, $request);
    }

    /**
     * Hold a file against a staged session.
     *
     * Deliberately NOT evidence yet: nothing references it, no `stage` has been
     * derived from an actor, and the form that minted this may still be
     * abandoned. It becomes evidence in `claim()`.
     */
    public function stage(UploadSession $session, UploadedFile $file, ?string $caption = null): UploadSessionFile
    {
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = $file->getSize();
        $original = $file->getClientOriginalName();

        $path = $file->store("uploads/staged/{$session->id}", 'public');

        return $session->files()->create([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'original_name' => $original,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'caption' => $caption !== null && trim($caption) !== '' ? trim($caption) : null,
        ]);
    }

    /**
     * The document finally exists - attach everything the phone sent while the
     * form was still being filled in.
     *
     * Acts as `created_by`, exactly as a live session does, because the handler
     * derives `stage` from the actor. Files are marked `attached_at`, so a
     * double submit cannot file the same photograph twice.
     *
     * @return int how many files were attached
     */
    public function claim(UploadSession $session, Model $target, User $actor): int
    {
        if (! $session->isStaging()) {
            throw new UploadSessionException('That upload was already tied to a record.');
        }
        if ((int) $session->created_by !== (int) $actor->id) {
            throw new UploadSessionException('That upload belongs to somebody else.');
        }

        $handler = $this->handler($session);
        $attached = 0;

        DB::transaction(function () use ($session, $target, $actor, $handler, &$attached) {
            foreach ($session->files()->whereNull('attached_at')->get() as $file) {
                $handler->attachStaged($target, $file, $actor, $session);
                $file->forceFill(['attached_at' => now()])->save();
                $attached++;
            }

            $session->forceFill([
                'attachable_type' => $target->getMorphClass(),
                'attachable_id' => $target->getKey(),
                'claimed_at' => now(),
            ])->save();
        });

        return $attached;
    }

    /** A human deciding the screen was seen by the wrong person. */
    public function revoke(UploadSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Close every live session on a document because the document has settled.
     *
     * Call this from wherever approval/rejection happens: a claim that can no
     * longer take evidence must not leave a token alive that says it can.
     */
    public function closeFor(Model $target): int
    {
        return UploadSession::query()
            ->for($target)
            ->usable()
            ->update(['consumed_at' => now()]);
    }

    /** The absolute HTTPS URL the QR code encodes. */
    public function url(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/u/{$token}";
    }

    /**
     * SHA-256, unsalted and deliberately so. This is a 32-character random
     * token, not a password: there is no dictionary to attack, and the lookup
     * has to be a single indexed equality check rather than a table scan.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
