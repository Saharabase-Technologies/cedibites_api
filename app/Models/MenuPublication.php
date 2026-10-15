<?php

namespace App\Models;

use App\Enums\MenuPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One press of "Put new prices live": who, when, and what changed.
 */
class MenuPublication extends Model
{
    protected $fillable = [
        'status',
        'publish_at',
        'published_at',
        'undone_at',
        'scheduled_by',
        'published_by',
        'undone_by',
        'change_count',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => MenuPublicationStatus::class,
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'undone_at' => 'datetime',
            'change_count' => 'integer',
        ];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(MenuPublicationChange::class);
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
