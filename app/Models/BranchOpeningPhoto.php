<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo against one checklist line: the problem as it was found, or the fix.
 */
class BranchOpeningPhoto extends Model
{
    public const REPORTED = 'reported';

    public const FIXED = 'fixed';

    protected $fillable = [
        'branch_opening_answer_id', 'stage', 'path', 'url', 'thumb_url',
        'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(BranchOpeningAnswer::class, 'branch_opening_answer_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
