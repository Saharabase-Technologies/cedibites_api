<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One slide of a release walkthrough.
 *
 * Ordered by `position` rather than by id, so an admin can reorder slides
 * without the ordering depending on the sequence they happened to be typed in.
 */
class StaffMessageStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_message_id',
        'position',
        'title',
        'body',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Public URL for this slide's image, or null.
     *
     * Built at read time rather than stored, so relocating storage does not
     * strand every slide that was ever written.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }
}
