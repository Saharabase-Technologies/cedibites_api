<?php

namespace App\Services\Openings;

use App\Domain\Inventory\Closing\DailyClosingService;
use App\Events\BranchAccessUpdatedEvent;
use App\Models\Branch;
use App\Models\BranchOpening;
use App\Models\BranchOpeningAnswer;
use App\Models\BranchOpeningPhoto;
use App\Models\OpeningChecklistItem;
use App\Models\User;
use App\Services\Media\EvidenceImageProcessor;
use App\Services\Platform\RuntimeSettings;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Opening a branch for the day.
 *
 * The branch manager answers the opening checklist and opens the branch. Until
 * then the branch sells nothing: not at the till, not by phone through the
 * call centre, and online only in the first minutes after opening time, when a
 * customer who knows the branch opens at ten waits a moment for the order to
 * reach the till.
 *
 * The point is accountability for the start of the day. A problem found in the
 * morning can be admitted and the branch opened anyway; head office is texted
 * at once and the manager has one grace period, for all of them together, to
 * fix it. What the manager cannot do is open with a food-safety item failing.
 * Head office can open any branch without the checklist, with a reason, and
 * that is recorded as unusual; the manager still has to complete it.
 *
 * Only branches switched on (branches.requires_opening_checklist) are held to
 * this, and `openings.enforced` in the settings panel turns it off for all of
 * them at once. A fault in these checks never stops a sale: the gates below
 * log it loudly and let the order through, the same rule the stock gate
 * follows. A branch that cannot trade because of a bug here is worse than the
 * bug.
 */
class BranchOpeningService
{
    public function __construct(
        private readonly RuntimeSettings $settings,
        private readonly OpeningAlerts $alerts,
    ) {}

    // ── Reading ────────────────────────────────────────────────────────────

    /** The IMS business day, which ends at 03:00. */
    public function businessDate(): string
    {
        return DailyClosingService::currentBusinessDate();
    }

    public function required(Branch $branch): bool
    {
        return (bool) $branch->requires_opening_checklist
            && (bool) $this->settings->get('openings.enforced');
    }

    public function schedule(Branch $branch, ?string $businessDate = null): OpeningSchedule
    {
        return OpeningSchedule::for($branch, $businessDate ?? $this->businessDate());
    }

    public function current(Branch $branch, ?string $businessDate = null): ?BranchOpening
    {
        return BranchOpening::query()
            ->where('branch_id', $branch->id)
            ->whereDate('business_date', $businessDate ?? $this->businessDate())
            ->first();
    }

    /**
     * Where a branch stands today, in one word.
     *
     *   not_required           this branch does not use the checklist
     *   closed_today           it does, but it is not trading today
     *   not_started            nobody has started today's checklist
     *   in_progress            started, not yet opened
     *   opened_by_head_office  selling, the checklist still to be completed
     *   open_with_problems     selling, with problems not yet fixed
     *   open                   selling
     */
    public function status(Branch $branch, ?BranchOpening $opening = null, ?OpeningSchedule $schedule = null): string
    {
        if (! $this->required($branch)) {
            return 'not_required';
        }

        $schedule ??= $this->schedule($branch);
        $opening ??= $this->current($branch);

        if (! $schedule->tradingDay) {
            return 'closed_today';
        }

        if ($opening === null || (! $opening->isStarted() && ! $opening->isOpen())) {
            return 'not_started';
        }

        if (! $opening->isOpen()) {
            return 'in_progress';
        }

        if (! $opening->isCompleted()) {
            return 'opened_by_head_office';
        }

        $opening->loadMissing('answers');

        return $opening->outstandingProblems()->isNotEmpty() ? 'open_with_problems' : 'open';
    }

    /**
     * What anybody may know about today's opening, customers included: whether
     * the branch is open and, if not, whether an order placed now will wait for
     * it. Nothing about who, or what went wrong.
     *
     * @return array<string, mixed>
     */
    public function summary(Branch $branch): array
    {
        try {
            $required = $this->required($branch);

            if (! $required) {
                return ['required' => false, 'status' => 'not_required', 'opened' => true, 'getting_ready' => false];
            }

            $schedule = $this->schedule($branch);
            $opening = $this->current($branch);
            $status = $this->status($branch, $opening, $schedule);
            $opened = $opening?->isOpen() ?? false;

            return [
                'required' => true,
                'business_date' => $schedule->businessDate,
                'status' => $status,
                'opened' => $opened || ! $schedule->tradingDay,
                'getting_ready' => ! $opened && $this->inOnlineWait($schedule),
                'opens_at' => $schedule->opensAt?->toIso8601String(),
                'checklist_from' => $schedule->checklistFrom()?->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            $this->logFault('summary', $branch, $e);

            return ['required' => false, 'status' => 'not_required', 'opened' => true, 'getting_ready' => false];
        }
    }

    /**
     * Staff may sign in to prepare from two hours before opening, whatever the
     * timetable says, so the manager can do the checklist and the kitchen can
     * get going.
     */
    public function allowsStaffEarly(Branch $branch): bool
    {
        try {
            return $this->required($branch) && $this->schedule($branch)->isInPreOpenWindow();
        } catch (\Throwable $e) {
            $this->logFault('staff access', $branch, $e);

            return false;
        }
    }

    /**
     * Opened today and not yet past closing time, so the till may sell ahead
     * of the timetable: a branch opened at 9:40 sells at 9:40.
     */
    public function openedAndBeforeClosing(Branch $branch): bool
    {
        try {
            if (! $this->required($branch)) {
                return false;
            }

            $opening = $this->current($branch);

            return $opening?->isOpen() === true && $this->schedule($branch)->isBeforeClosing();
        } catch (\Throwable $e) {
            $this->logFault('early opening', $branch, $e);

            return false;
        }
    }

    // ── Gates ──────────────────────────────────────────────────────────────

    /**
     * Why the till may not sell at this branch right now, or null if it may.
     *
     * Covers every order a member of staff places: the till, the manager's New
     * Order screen and the call centre, which all go through the same door.
     */
    public function refusalForTill(Branch $branch): ?OpeningException
    {
        try {
            if (! $this->required($branch) || ! $this->schedule($branch)->tradingDay) {
                return null;
            }

            if ($this->current($branch)?->isOpen()) {
                return null;
            }

            return new OpeningException(
                "{$branch->name} has not been opened for today. The manager opens it with the opening checklist.",
                'branch_not_opened',
            );
        } catch (\Throwable $e) {
            $this->logFault('till gate', $branch, $e);

            return null;
        }
    }

    /**
     * Why a customer may not order from this branch online right now.
     *
     * Asked after the timetable has already said the branch is open. In the
     * first minutes after opening time an order is taken and waits for the
     * till; after that the branch is late, and online stops taking money for
     * food nobody is cooking.
     */
    public function refusalForOnline(Branch $branch): ?OpeningException
    {
        try {
            if (! $this->required($branch)) {
                return null;
            }

            $schedule = $this->schedule($branch);

            if (! $schedule->tradingDay || $this->current($branch)?->isOpen() || $this->inOnlineWait($schedule)) {
                return null;
            }

            return new OpeningException(
                "{$branch->name} has not opened yet today. Please choose another branch, or try again a little later.",
                'branch_not_opened',
            );
        } catch (\Throwable $e) {
            $this->logFault('online gate', $branch, $e);

            return null;
        }
    }

    // ── The manager's checklist ────────────────────────────────────────────

    public function start(Branch $branch, User $user, string $via): BranchOpening
    {
        if (! $this->required($branch)) {
            throw new OpeningException("{$branch->name} does not use the opening checklist.", 'not_required');
        }

        $schedule = $this->schedule($branch);

        if (! $schedule->tradingDay) {
            throw new OpeningException("{$branch->name} is not open today.", 'not_trading');
        }

        if ($schedule->isBeforeChecklistWindow()) {
            throw new OpeningException(
                'The checklist opens at '.$schedule->checklistFrom()->format('g:i a').'.',
                'too_early',
                ['checklist_from' => $schedule->checklistFrom()->toIso8601String()],
            );
        }

        $opening = $this->row($branch, $schedule->businessDate);

        if (! $opening->isStarted()) {
            DB::transaction(function () use ($opening, $user) {
                $opening->update(['started_at' => now(), 'started_by' => $user->id]);
                $this->copyChecklist($opening);
            });

            activity('openings')
                ->causedBy($user)
                ->performedOn($opening->branch)
                ->event('opening_started')
                ->withProperties(['business_date' => $schedule->businessDate, 'via' => $via])
                ->log("{$branch->name}: opening checklist started");

            $this->broadcast($branch);
        }

        return $opening->fresh(['answers.photos', 'branch']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function answer(BranchOpeningAnswer $answer, User $user, array $data): BranchOpeningAnswer
    {
        $opening = $answer->opening;

        if ($opening->isCompleted()) {
            throw new OpeningException(
                'This checklist was finished at '.$opening->completed_at->format('g:i a').'. Mark a problem fixed instead.',
                'checklist_completed',
            );
        }

        $changes = match ($answer->kind) {
            OpeningChecklistItem::KIND_CHECK => $this->checkAnswer($answer, $data),
            OpeningChecklistItem::KIND_NUMBER => $this->numberAnswer($data),
            default => ['value' => $this->text($data['value'] ?? null, 2000)],
        };

        $answer->update($changes + ['answered_by' => $user->id, 'answered_at' => now()]);

        return $answer->fresh(['photos', 'answerer']);
    }

    /**
     * Finish the checklist. If head office has not already opened the branch,
     * this is what opens it.
     */
    public function complete(BranchOpening $opening, User $user, string $via, ?string $note = null): BranchOpening
    {
        $opening->load(['answers', 'branch']);

        if ($opening->isCompleted()) {
            return $opening;
        }

        if (! $opening->isStarted()) {
            throw new OpeningException('Start the checklist first.', 'not_started');
        }

        $unanswered = $opening->answers->reject->isAnswered()->values();
        if ($unanswered->isNotEmpty()) {
            throw new OpeningException(
                $unanswered->count() === 1
                    ? 'One line still needs an answer.'
                    : "{$unanswered->count()} lines still need an answer.",
                'checklist_incomplete',
                ['unanswered' => $unanswered->pluck('id')->all()],
            );
        }

        // The client's rule: fix it or escalate it. Food safety cannot be
        // admitted and opened on; it has to be fixed, or head office has to
        // take the decision.
        $unsafe = $opening->answers->filter(fn (BranchOpeningAnswer $a) => $a->mustPass() && $a->isProblem())->values();
        if ($unsafe->isNotEmpty() && ! $opening->isOpen()) {
            throw new OpeningException(
                "{$opening->branch->name} cannot open with a food-safety problem: ".$unsafe->pluck('short')->implode(', ')
                .'. Fix it and change the answer, or ask head office to open the branch.',
                'food_safety',
                ['items' => $unsafe->pluck('id')->all()],
            );
        }

        $schedule = $this->schedule($opening->branch, $opening->business_date->toDateString());
        $openedNow = ! $opening->isOpen();
        $problems = $opening->problems();

        DB::transaction(function () use ($opening, $user, $via, $note, $openedNow, $problems) {
            $opening->update(array_filter([
                'completed_at' => now(),
                'completed_by' => $user->id,
                'unresolved_note' => $this->text($note, 2000),
                'opened_at' => $openedNow ? now() : null,
                'opened_by' => $openedNow ? $user->id : null,
                'opened_via' => $openedNow ? $via : null,
                'grace_ends_at' => $problems->isNotEmpty()
                    ? now()->addMinutes(max(1, (int) config('openings.grace_minutes', 60)))
                    : null,
            ], fn ($v) => $v !== null));
        });

        $opening->refresh()->load(['answers', 'branch', 'completer']);

        activity('openings')
            ->causedBy($user)
            ->performedOn($opening->branch)
            ->event($openedNow ? 'branch_opened' : 'opening_checklist_completed')
            ->withProperties([
                'business_date' => $opening->business_date->toDateString(),
                'via' => $via,
                'problems' => $problems->pluck('short')->all(),
            ])
            ->log($openedNow
                ? "{$opening->branch->name} opened".($problems->isNotEmpty() ? " with {$problems->count()} ".Str::plural('problem', $problems->count()) : '')
                : "{$opening->branch->name}: opening checklist completed after head office opened it");

        if ($problems->isNotEmpty()) {
            $this->alerts->openedWithProblems($opening);
        }

        // Head office was told it was late, so they are told when it opened.
        if ($openedNow && $opening->late_alerted_at !== null && $schedule->opensAt) {
            $this->alerts->openedLate($opening, $schedule);
        }

        $this->broadcast($opening->branch);

        return $opening->fresh(['answers.photos', 'branch']);
    }

    public function resolve(BranchOpeningAnswer $answer, User $user, string $note): BranchOpeningAnswer
    {
        $opening = $answer->opening()->with(['answers', 'branch'])->first();

        if (! $opening->isCompleted()) {
            throw new OpeningException('The checklist is not finished yet. Change the answer instead.', 'not_completed');
        }

        if (! $answer->isOutstanding()) {
            throw new OpeningException('That line has no open problem.', 'nothing_to_fix');
        }

        $note = $this->text($note, 2000);
        if ($note === null || mb_strlen($note) < 3) {
            throw new OpeningException('Say what was done to fix it.', 'note_required');
        }

        $answer->update(['resolved_at' => now(), 'resolved_by' => $user->id, 'resolution_note' => $note]);

        $opening->refresh()->load(['answers', 'branch']);

        activity('openings')
            ->causedBy($user)
            ->performedOn($opening->branch)
            ->event('opening_problem_fixed')
            ->withProperties(['business_date' => $opening->business_date->toDateString(), 'item' => $answer->short, 'note' => $note])
            ->log("{$opening->branch->name}: {$answer->short} fixed");

        if ($opening->outstandingProblems()->isEmpty()) {
            $opening->update(['problems_resolved_at' => now()]);
            $this->alerts->allFixed($opening->fresh(['answers', 'branch']));
        }

        $this->broadcast($opening->branch);

        return $answer->fresh(['photos', 'resolver']);
    }

    // ── Head office ────────────────────────────────────────────────────────

    /**
     * Open a branch without its checklist. Unusual by design: it needs a
     * reason, it is logged as such, and head office is texted. The manager
     * still has to complete the checklist afterwards.
     */
    public function openByHeadOffice(Branch $branch, User $user, string $reason): BranchOpening
    {
        if (! $this->required($branch)) {
            throw new OpeningException("{$branch->name} does not use the opening checklist, so it does not need opening.", 'not_required');
        }

        $reason = $this->text($reason, 500);
        if ($reason === null || mb_strlen($reason) < 10) {
            throw new OpeningException('Give the reason in a sentence. It goes on the record.', 'reason_required');
        }

        $schedule = $this->schedule($branch);
        $opening = $this->row($branch, $schedule->businessDate);

        if ($opening->isOpen()) {
            throw new OpeningException("{$branch->name} is already open.", 'already_open');
        }

        DB::transaction(function () use ($opening, $user, $reason) {
            $opening->update([
                'opened_at' => now(),
                'opened_by' => $user->id,
                'opened_via' => 'admin',
                'is_override' => true,
                'override_reason' => $reason,
            ]);

            // So the manager has something to complete afterwards.
            $this->copyChecklist($opening);
        });

        $opening->refresh()->load(['branch', 'answers']);

        activity('openings')
            ->causedBy($user)
            ->performedOn($branch)
            ->event('branch_opened_without_checklist')
            ->withProperties([
                'business_date' => $schedule->businessDate,
                'reason' => $reason,
                'unusual' => true,
                'checklist_started' => $opening->isStarted(),
            ])
            ->log("{$branch->name} opened by head office without the checklist");

        $this->alerts->openedByHeadOffice($opening, $user, $reason);
        $this->broadcast($branch);

        return $opening->fresh(['answers.photos', 'branch']);
    }

    // ── Evidence ───────────────────────────────────────────────────────────

    public function attachPhoto(BranchOpeningAnswer $answer, UploadedFile $file, User $user): BranchOpeningPhoto
    {
        // Sniffed, not what the browser claimed; read before the temp file goes.
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = $file->getSize();

        try {
            $path = $file->store("branch-openings/{$answer->branch_opening_id}", 'public');
        } catch (\Throwable $e) {
            throw new OpeningException('That photo could not be saved. Try again.', 'upload_failed');
        }

        // Never allowed to fail the upload: the photo matters, the thumbnail does not.
        $derivatives = rescue(fn () => app(EvidenceImageProcessor::class)->process($path, $mime), null, report: false) ?? [];

        return $answer->photos()->create([
            'stage' => $answer->resolved_at ? BranchOpeningPhoto::FIXED : BranchOpeningPhoto::REPORTED,
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'thumb_url' => $derivatives['thumb_url'] ?? null,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'uploaded_by' => $user->id,
        ]);
    }

    public function deletePhoto(BranchOpeningPhoto $photo, User $user): void
    {
        if ((int) $photo->uploaded_by !== (int) $user->id) {
            throw new OpeningException('Only the person who took a photo can remove it.', 'not_yours');
        }

        Storage::disk('public')->delete($photo->path);
        $photo->delete();
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /** Today's row for a branch, made if missing. Safe against two tills at once. */
    public function row(Branch $branch, string $businessDate): BranchOpening
    {
        $existing = $this->current($branch, $businessDate);
        if ($existing) {
            return $existing->setRelation('branch', $branch);
        }

        try {
            return BranchOpening::create(['branch_id' => $branch->id, 'business_date' => $businessDate])
                ->setRelation('branch', $branch);
        } catch (QueryException) {
            // The other till made it a moment ago.
            return $this->current($branch, $businessDate)->setRelation('branch', $branch);
        }
    }

    public function inOnlineWait(OpeningSchedule $schedule, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $schedule->tradingDay
            && $schedule->opensAt !== null
            && $at->gte($schedule->opensAt)
            && $at->lte($schedule->onlineWaitUntil());
    }

    public function broadcast(Branch $branch): void
    {
        try {
            BranchAccessUpdatedEvent::dispatch($branch->fresh());
        } catch (\Throwable $e) {
            // A till that misses the push still refetches within a minute.
            Log::warning('Could not broadcast a branch opening change', ['branch_id' => $branch->id, 'error' => $e->getMessage()]);
        }
    }

    /** Copy today's checklist onto the opening, once. */
    private function copyChecklist(BranchOpening $opening): void
    {
        if ($opening->answers()->exists()) {
            return;
        }

        $now = now();

        $rows = OpeningChecklistItem::query()->active()->get()->map(fn (OpeningChecklistItem $item) => [
            'branch_opening_id' => $opening->id,
            'checklist_item_id' => $item->id,
            'key' => $item->key,
            'section' => $item->section,
            'group' => $item->group,
            'label' => $item->label,
            'short' => $item->short,
            'help' => $item->help,
            'kind' => $item->kind,
            'weight' => $item->weight,
            'allows_na' => $item->allows_na,
            'position' => $item->position,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            BranchOpeningAnswer::insert($rows);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checkAnswer(BranchOpeningAnswer $answer, array $data): array
    {
        $choice = $data['answer'] ?? null;
        $allowed = [BranchOpeningAnswer::OK, BranchOpeningAnswer::PROBLEM];
        if ($answer->allows_na) {
            $allowed[] = BranchOpeningAnswer::NOT_APPLICABLE;
        }

        if ($choice === null) {
            return ['answer' => null, 'note' => $this->text($data['note'] ?? null, 1000)];
        }

        if (! in_array($choice, $allowed, true)) {
            throw new OpeningException('That is not an answer this line takes.', 'invalid_answer');
        }

        $note = $this->text($data['note'] ?? null, 1000);

        // Admitting a problem means saying what it is. "Problem" on its own
        // tells head office nothing they can act on.
        if ($choice === BranchOpeningAnswer::PROBLEM && ($note === null || mb_strlen($note) < 3)) {
            throw new OpeningException('Say what the problem is.', 'note_required');
        }

        return ['answer' => $choice, 'note' => $note];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function numberAnswer(array $data): array
    {
        $value = $data['value'] ?? null;

        if ($value === null || $value === '') {
            return ['value' => null];
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > 999) {
            throw new OpeningException('Enter a whole number.', 'invalid_number');
        }

        return ['value' => (string) (int) $value];
    }

    private function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private function logFault(string $where, Branch $branch, \Throwable $e): void
    {
        // Critical, so it reaches the error feed and the tech admin's phone.
        Log::critical("Opening check failed ({$where}); letting the branch trade", [
            'branch_id' => $branch->id,
            'error' => $e->getMessage(),
        ]);
    }
}
