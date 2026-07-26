<?php

namespace App\Services\Uploads\Contracts;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * What a `purpose` actually means.
 *
 * The upload-session machinery is deliberately general - it knows about tokens,
 * expiry and rate limits, and nothing whatsoever about wastage. Everything
 * domain-shaped lives behind this interface, one implementation per purpose,
 * mapped in `config/upload-sessions.php`.
 *
 * Adding deliveries or daily counts later means writing one of these, not
 * touching the session code.
 */
interface UploadSessionHandler
{
    /**
     * May a session be issued against this target at all?
     *
     * Checked at issue time so the desktop refuses to draw a QR code for a
     * document that has already been settled, rather than sending someone to
     * the store room to find out.
     */
    public function canIssue(Model $target, User $actor): bool;

    /**
     * ONE LINE for the phone screen, and no more.
     *
     * The token is a bearer credential inside a screenshot-able square. Whoever
     * holds it gets to see whatever this returns, so it must be enough to
     * confirm you are photographing the right crate and nothing else. Never
     * quantities, values, names, or anything about the dispute.
     */
    public function label(Model $target): string;

    /** The document's human reference, e.g. `WST-260726-004`. */
    public function reference(Model $target): string;

    /**
     * Laravel validation rules for a single uploaded file.
     *
     * Owned by the handler, not the controller: evidence of spoiled food has
     * different needs from, say, a signed delivery note.
     *
     * @return array<int, mixed>
     */
    public function fileRules(): array;

    /**
     * Attach the file, acting as `$actor` - the user who was authenticated at
     * the laptop when the QR was generated, NOT an anonymous phone.
     *
     * This is load-bearing. WastageService derives `stage` (declared vs
     * inspection) from the actor, so handing it the wrong person silently files
     * the branch's evidence under the approver's name.
     *
     * Implementations are responsible for dispatching whatever broadcast makes
     * the laptop update live.
     */
    public function handle(Model $target, UploadedFile $file, User $actor, ?string $caption, UploadSession $session): void;
}
