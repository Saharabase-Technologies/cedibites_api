<?php

namespace App\Models;

use App\Services\Openings\Relevance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One branch, one business day: who opened it, when, and on what footing.
 *
 * A row can exist before anyone has started, because the watcher records here
 * that head office was told the branch was late.
 */
class BranchOpening extends Model
{
    protected $fillable = [
        'branch_id', 'business_date',
        'started_at', 'started_by', 'completed_at', 'completed_by',
        'opened_at', 'opened_by', 'opened_via', 'is_override', 'override_reason',
        'unresolved_note', 'grace_ends_at', 'problems_resolved_at',
        'late_alerted_at', 'late_reminded_at', 'grace_alerted_at', 'last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'opened_at' => 'datetime',
            'is_override' => 'boolean',
            'grace_ends_at' => 'datetime',
            'problems_resolved_at' => 'datetime',
            'late_alerted_at' => 'datetime',
            'late_reminded_at' => 'datetime',
            'grace_alerted_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(BranchOpeningAnswer::class)->orderBy('position')->orderBy('id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function isStarted(): bool
    {
        return $this->started_at !== null;
    }

    public function isOpen(): bool
    {
        return $this->opened_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * The lines that are asked, given the answers so far. A line whose question
     * no longer applies ("cover for absent staff" once everybody has reported)
     * is left out of everything: not required, not a problem, not counted.
     *
     * @return Collection<int, BranchOpeningAnswer>
     */
    public function relevantAnswers(): Collection
    {
        $asked = Relevance::of($this->answers);

        return $this->answers->filter(fn (BranchOpeningAnswer $a) => $asked[$a->id] ?? true)->values();
    }

    /**
     * Problems admitted and not yet fixed.
     *
     * @return Collection<int, BranchOpeningAnswer>
     */
    public function outstandingProblems(): Collection
    {
        return $this->relevantAnswers()->filter(fn (BranchOpeningAnswer $a) => $a->isOutstanding())->values();
    }

    /**
     * @return Collection<int, BranchOpeningAnswer>
     */
    public function problems(): Collection
    {
        return $this->relevantAnswers()->filter(fn (BranchOpeningAnswer $a) => $a->isProblem())->values();
    }
}
