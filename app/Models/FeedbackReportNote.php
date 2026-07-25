<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One per-page note on a feedback report — text, a voice clip, or both, pinned
 * to the route it was recorded on. See the feedback_report_notes migration.
 */
class FeedbackReportNote extends Model
{
    protected $fillable = [
        'feedback_report_id',
        'route',
        'page_title',
        'body',
        'audio_url',
        'transcript',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }
}
