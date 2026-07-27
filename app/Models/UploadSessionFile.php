<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a phone sent to a session whose document did not exist yet.
 *
 * It is NOT evidence while it sits here. Nothing references it, no `stage` has
 * been derived from an actor, and it belongs to a form somebody may yet abandon.
 * It becomes evidence at the moment the session is claimed and the file is
 * attached to the document the form finally created.
 */
class UploadSessionFile extends Model
{
    protected $fillable = [
        'upload_session_id',
        'path',
        'url',
        'original_name',
        'mime_type',
        'size_bytes',
        'caption',
        'attached_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'attached_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class, 'upload_session_id');
    }

    /** Video or still, decided by the sniffed type rather than the filename. */
    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }
}
