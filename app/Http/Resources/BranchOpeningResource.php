<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\BranchOpening;
use App\Services\Openings\BranchOpeningService;
use App\Services\Openings\OpeningSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch's opening for one business day, as its manager, its staff and head
 * office see it.
 *
 * Built from the branch rather than the row, because a day nobody has started
 * has no row and still has a status, a schedule and a lateness to report.
 */
class BranchOpeningResource extends JsonResource
{
    public function __construct(
        private readonly Branch $branch,
        private readonly ?BranchOpening $opening,
        private readonly bool $withAnswers = true,
    ) {
        parent::__construct($opening);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = app(BranchOpeningService::class);
        $date = $this->opening?->business_date?->toDateString() ?? $service->businessDate();
        $schedule = OpeningSchedule::for($this->branch, $date);
        $opening = $this->opening?->loadMissing(['answers', 'starter', 'completer', 'opener']);

        $answers = $opening?->answers ?? collect();
        $problems = $answers->filter->isProblem();
        $isToday = $date === $service->businessDate();

        return [
            'id' => $opening?->id,
            'branch' => ['id' => $this->branch->id, 'name' => $this->branch->name],
            'business_date' => $date,
            'required' => $service->required($this->branch),
            'status' => $isToday
                ? $service->status($this->branch, $opening, $schedule)
                : $this->pastStatus($opening),
            'schedule' => [
                'trading_day' => $schedule->tradingDay,
                'opens_at' => $schedule->opensAt?->toIso8601String(),
                'closes_at' => $schedule->closesAt?->toIso8601String(),
                'checklist_from' => $schedule->checklistFrom()?->toIso8601String(),
                'late_at' => $schedule->lateAt()?->toIso8601String(),
            ],
            'is_late' => $schedule->tradingDay && $schedule->lateAt() !== null && (
                $opening?->opened_at
                    ? $opening->opened_at->gt($schedule->lateAt())
                    : ($isToday && now()->gt($schedule->lateAt()))
            ),
            'started_at' => $opening?->started_at?->toIso8601String(),
            'started_by' => $opening?->starter?->name,
            'completed_at' => $opening?->completed_at?->toIso8601String(),
            'completed_by' => $opening?->completer?->name,
            'opened_at' => $opening?->opened_at?->toIso8601String(),
            'opened_by' => $opening?->opener?->name,
            'opened_via' => $opening?->opened_via,
            'is_override' => (bool) $opening?->is_override,
            'override_reason' => $opening?->override_reason,
            'unresolved_note' => $opening?->unresolved_note,
            'grace_ends_at' => $opening?->grace_ends_at?->toIso8601String(),
            'problems_resolved_at' => $opening?->problems_resolved_at?->toIso8601String(),
            'progress' => [
                'answered' => $answers->filter->isRequired()->filter->isAnswered()->count(),
                'total' => $answers->filter->isRequired()->count(),
            ],
            'problems' => [
                'total' => $problems->count(),
                'outstanding' => $problems->filter->isOutstanding()->count(),
                'items' => $problems->map(fn ($a) => [
                    'id' => $a->id,
                    'short' => $a->short,
                    'note' => $a->note,
                    'resolved_at' => $a->resolved_at?->toIso8601String(),
                ])->values(),
            ],
            'answers' => $this->withAnswers && $opening
                ? BranchOpeningAnswerResource::collection(
                    $opening->answers()->with(['photos', 'answerer', 'resolver'])->get()
                )
                : [],
        ];
    }

    /** A past day is described by what happened, not by the clock. */
    private function pastStatus(?BranchOpening $opening): string
    {
        if ($opening === null || ! $opening->isOpen()) {
            return 'not_opened';
        }

        if (! $opening->isCompleted()) {
            return 'opened_by_head_office';
        }

        return $opening->outstandingProblems()->isNotEmpty() ? 'open_with_problems' : 'open';
    }
}
