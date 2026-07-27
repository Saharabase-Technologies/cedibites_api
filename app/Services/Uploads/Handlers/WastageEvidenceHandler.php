<?php

namespace App\Services\Uploads\Handlers;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Wastage\WastageService;
use App\Events\Inventory\WastageBroadcastEvent;
use App\Models\Inventory\Wastage;
use App\Models\UploadSession;
use App\Models\UploadSessionFile;
use App\Models\User;
use App\Rules\EvidenceMedia;
use App\Services\Uploads\Contracts\UploadSessionHandler;
use App\Services\Uploads\UploadSessionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Phone-as-camera evidence for a wastage claim - the first consumer of upload
 * sessions, and the reason they exist.
 *
 * *"So show me the food that has gone bad."* The manager is standing over a
 * crate on the floor and the IMS is on a laptop upstairs. This closes that gap.
 */
class WastageEvidenceHandler implements UploadSessionHandler
{
    public const PURPOSE = 'wastage_evidence';

    public function __construct(
        private readonly WastageService $wastages,
    ) {}

    /**
     * Two conditions, and both are the same ones the desktop endpoint applies.
     * A QR code is not a way around either: you may only photograph a claim you
     * could have uploaded to from your own laptop, and only while it is live.
     */
    public function canIssue(Model $target, User $actor): bool
    {
        return $target instanceof Wastage
            && $target->acceptsEvidence()
            && $target->isVisibleTo($actor);
    }

    /**
     * One line, and it says what to do rather than what the claim contains.
     *
     * Whoever holds the token sees this, and the token is a bearer credential
     * inside a square anyone near the laptop could have photographed. The
     * reference alongside it is enough to confirm you are at the right crate;
     * quantities, values and who is arguing with whom are none of its business.
     */
    public function label(?Model $target): string
    {
        return 'Photos or video of the goods being written off.';
    }

    public function reference(?Model $target): ?string
    {
        // A staged session has no claim yet - the form is still open. The phone
        // shows the label alone, which is all it needs to photograph a crate.
        return $target === null ? null : $this->wastage($target)->reference;
    }

    /**
     * Anyone who could raise the claim can photograph the goods before it
     * exists. That is the whole point: the crate is in front of them now, and
     * the notes are still being typed.
     */
    public function canStage(User $actor): bool
    {
        return $actor->can('inventory.wastage.record');
    }

    /**
     * Attach a photo that arrived before the claim did.
     *
     * The file is already on disk from `stage()`, so this records it rather
     * than storing it again - and as `$actor`, so `stage` still derives from
     * whoever generated the code.
     */
    public function attachStaged(
        Model $target,
        UploadSessionFile $file,
        User $actor,
        UploadSession $session,
    ): void {
        $wastage = $this->wastage($target);

        try {
            $this->wastages->attachStoredPhoto($wastage, $file, $actor);
        } catch (InventoryException $e) {
            throw new UploadSessionException($e->getMessage(), previous: $e);
        }
    }

    /** @return array<int, mixed> */
    public function fileRules(): array
    {
        return ['required', 'file', new EvidenceMedia];
    }

    /**
     * Attach, acting as the user who generated the QR at the laptop.
     *
     * This is the load-bearing line of the whole feature. WastageService derives
     * `stage` - `declared` for the claimant, `inspection` for anyone else - from
     * the actor. An anonymous or system actor would file the branch's own
     * photos as somebody else's inspection and quietly destroy the one thing
     * that makes the photo set readable as an argument.
     */
    public function handle(
        Model $target,
        UploadedFile $file,
        User $actor,
        ?string $caption,
        UploadSession $session,
    ): void {
        $wastage = $this->wastage($target);

        // Re-checked here, not just at issue time: a claim can be approved in
        // the minutes between the QR appearing and the phone uploading, and the
        // photo set has to stay exactly what the decision was made on.
        if (! $wastage->acceptsEvidence()) {
            throw new UploadSessionException(
                'This claim has been settled since the code was scanned, so nothing further can be added to it.'
            );
        }

        try {
            $this->wastages->attachPhoto($wastage, $file, $actor, $caption);
        } catch (InventoryException $e) {
            // Translate at the boundary. The public controller is general and
            // must not know about inventory exceptions, but these messages are
            // worth keeping - they say why, and the person reading them on a
            // phone is the same member of staff who would see them on a laptop.
            throw new UploadSessionException($e->getMessage(), previous: $e);
        }

        // The laptop is sitting on the claim watching. `useInventoryRealtime`
        // already fans `wastage.updated` out to the wastage queries, so the
        // photo appears on the desktop without anyone reloading - which is the
        // entire point of scanning a code instead of emailing yourself a photo.
        WastageBroadcastEvent::dispatch(
            $wastage->id,
            $wastage->reference,
            $wastage->status->value,
            'evidence',
        );
    }

    private function wastage(Model $target): Wastage
    {
        if (! $target instanceof Wastage) {
            throw new UploadSessionException('This link does not point at a wastage claim.');
        }

        return $target;
    }
}
