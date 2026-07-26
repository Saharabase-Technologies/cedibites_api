<?php

namespace App\Observers;

use App\Models\Inventory\Wastage;
use App\Services\Uploads\UploadSessionService;
use Illuminate\Support\Facades\Log;

class WastageObserver
{
    public function __construct(
        private readonly UploadSessionService $sessions,
    ) {}

    /**
     * A settled claim must not leave a live QR code behind it.
     *
     * Once a claim is approved or refused, its photo set is the record of what
     * the decision was made on - so the token that could still add to it has to
     * die at the same moment, not nine minutes later.
     *
     * Deliberately an observer rather than three lines in the controller: there
     * are already four ways a claim settles (approve, reject, cancel, and the
     * automatic write-off under the threshold), and a fifth added next year
     * would otherwise quietly leave tokens alive.
     */
    public function updated(Wastage $wastage): void
    {
        if (! $wastage->wasChanged('status')) {
            return;
        }

        if ($wastage->acceptsEvidence()) {
            return;
        }

        try {
            $this->sessions->closeFor($wastage);
        } catch (\Throwable $e) {
            // Closing a token is hygiene, not correctness - the upload path
            // re-checks `acceptsEvidence()` before attaching anything. Never
            // let it fail an approval.
            Log::warning('Could not close upload sessions for settled wastage.', [
                'wastage_id' => $wastage->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
