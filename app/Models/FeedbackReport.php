<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A durable feedback report a human triages. See the feedback_reports migration.
 */
class FeedbackReport extends Model
{
    protected $fillable = [
        'number',
        'reporter_id',
        'branch_id',
        'role_at_report',
        'route',
        'severity',
        'description',
        'transcript',
        'audio_url',
        'replay_url',
        'replay_id',
        'screenshots',
        'breadcrumbs',
        'console_entries',
        'network_entries',
        'request_ids',
        'client_meta',
        'status',
        'assignee_id',
    ];

    protected function casts(): array
    {
        return [
            'screenshots' => 'array',
            'breadcrumbs' => 'array',
            'console_entries' => 'array',
            'network_entries' => 'array',
            'request_ids' => 'array',
            'client_meta' => 'array',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
