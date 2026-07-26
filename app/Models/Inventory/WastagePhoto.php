<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo attached to a wastage claim. See the migration for why `stage`
 * matters: `declared` is the claimant's account, `inspection` is the approver's.
 */
class WastagePhoto extends Model
{
    protected $table = 'inventory_wastage_photos';

    protected $fillable = [
        'wastage_id',
        'stage',
        'path',
        'url',
        // Smaller renditions for the grid and the lightbox. Nullable: video rows
        // have none, and neither does anything GD could not read. Every consumer
        // falls back to the original.
        'thumb_path',
        'thumb_url',
        'display_path',
        'display_url',
        'caption',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class, 'wastage_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
