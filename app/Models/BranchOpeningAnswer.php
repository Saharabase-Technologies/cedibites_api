<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One checklist line as it was asked that morning, and how it was answered.
 */
class BranchOpeningAnswer extends Model
{
    public const OK = 'ok';

    public const PROBLEM = 'problem';

    public const NOT_APPLICABLE = 'na';

    /**
     * Whether the line is asked, given every other answer. Not a column: it is
     * worked out for a response (see Relevance) and never saved.
     */
    public ?bool $asked = null;

    protected $fillable = [
        'branch_opening_id', 'checklist_item_id',
        'key', 'section', 'group', 'label', 'short', 'help', 'kind', 'weight', 'allows_na', 'position', 'show_if',
        'answer', 'value', 'note', 'answered_by', 'answered_at',
        'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'allows_na' => 'boolean',
            'position' => 'integer',
            'show_if' => 'array',
            'answered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(BranchOpening::class, 'branch_opening_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(BranchOpeningPhoto::class)->orderBy('id');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isCheck(): bool
    {
        return $this->kind === OpeningChecklistItem::KIND_CHECK;
    }

    public function mustPass(): bool
    {
        return $this->weight === OpeningChecklistItem::WEIGHT_MUST_PASS;
    }

    public function isProblem(): bool
    {
        return $this->isCheck() && $this->answer === self::PROBLEM;
    }

    public function isOutstanding(): bool
    {
        return $this->isProblem() && $this->resolved_at === null;
    }

    /** Every line needs an answer except a note, which is optional. */
    public function isRequired(): bool
    {
        return $this->kind !== OpeningChecklistItem::KIND_TEXT;
    }

    /** Whether the checklist can be finished with this line as it stands. */
    public function isAnswered(): bool
    {
        return match ($this->kind) {
            OpeningChecklistItem::KIND_CHECK => $this->answer !== null,
            OpeningChecklistItem::KIND_NUMBER => $this->value !== null && $this->value !== '',
            // Notes are optional.
            default => true,
        };
    }
}
