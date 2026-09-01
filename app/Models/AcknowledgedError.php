<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dismissal on the platform error feed.
 *
 * @see \App\Services\SmartErrorService::fingerprint() for how the key is built.
 */
class AcknowledgedError extends Model
{
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'title',
        'category',
        'severity',
        'acknowledged_by',
        'acknowledged_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
