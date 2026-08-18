<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tap. Disposable by design — see the migration.
 */
class LinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'short_link_id',
        'clicked_at',
        'user_agent',
        'referer',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
