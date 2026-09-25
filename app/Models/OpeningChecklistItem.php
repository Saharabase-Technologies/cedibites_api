<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the opening checklist as head office wants it today.
 *
 * Each morning's opening copies these into BranchOpeningAnswer rows, so an edit
 * here changes tomorrow's checklist and never what was asked on an earlier day.
 */
class OpeningChecklistItem extends Model
{
    public const KIND_CHECK = 'check';

    public const KIND_NUMBER = 'number';

    public const KIND_TEXT = 'text';

    /** A failure here keeps the branch shut. Only head office can open it anyway. */
    public const WEIGHT_MUST_PASS = 'must_pass';

    /** A failure here can be admitted; the branch opens and the grace period starts. */
    public const WEIGHT_CAN_OPEN = 'can_open';

    /** A number or a note, kept for the record. */
    public const WEIGHT_RECORD = 'record';

    protected $fillable = [
        'key', 'section', 'group', 'label', 'short', 'help',
        'kind', 'weight', 'allows_na', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_na' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }
}
