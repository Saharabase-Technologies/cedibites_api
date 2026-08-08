<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One uploaded list. See the migration for why provenance is kept at all.
 */
class ContactImport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'label',
        'filename',
        'source_note',
        'uploaded_by_user_id',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'invalid_count',
        'already_customer_count',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'invalid_count' => 'integer',
            'already_customer_count' => 'integer',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * How many of this list's contacts have since ordered.
     *
     * Counted live rather than stored, because unlike the parse breakdown this
     * number is supposed to move — it is the only measure of whether buying the
     * list was worth anything.
     */
    public function convertedCount(): int
    {
        return $this->contacts()->converted()->count();
    }
}
