<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One diagnostic row per API request. Rolling and disposable — see the
 * request_logs migration. Only created_at is tracked.
 */
class RequestLog extends Model
{
    /** No updated_at — the row is written once and never mutated. */
    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'user_id',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
