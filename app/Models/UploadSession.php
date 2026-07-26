<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A short-lived, upload-only capability granting a phone the right to attach
 * media to exactly ONE document without logging in. See the migration for why
 * each column exists.
 *
 * Nothing here reads the raw token — it exists only in the QR code. Lookups go
 * through UploadSessionService::resolve(), which hashes before querying.
 */
class UploadSession extends Model
{
    protected $fillable = [
        'token_hash',
        'attachable_type',
        'attachable_id',
        'created_by',
        'purpose',
        'max_files',
        'files_uploaded',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'last_used_at',
        'last_ip',
        'last_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'max_files' => 'integer',
            'files_uploaded' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * `token_hash` is the credential. Never let it reach a JSON response by
     * accident — an API resource that spreads the model would otherwise hand
     * out the one value that must stay server-side.
     */
    protected $hidden = ['token_hash'];

    // ── Relations ────────────────────────────────────────────────────────────

    /** The document this session may attach media to. One document, not a type. */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The user the phone acts as. Not "who scanned" — nobody knows that. The
     * uploads land under this person's name because they are the one who was
     * authenticated at the laptop when the QR was generated.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->files_uploaded >= $this->max_files;
    }

    /**
     * One question, asked in one place, so the public endpoint and the desktop
     * "is my QR still good?" check can never disagree.
     */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->consumed_at === null
            && ! $this->isExpired()
            && ! $this->isFull();
    }

    /**
     * Why it is not usable, in words a person holding a phone can act on.
     * Deliberately does not distinguish "revoked" from "expired" beyond what
     * helps: either way the answer is to go back to the laptop.
     */
    public function unusableReason(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'This link was cancelled. Show the QR code again on the computer to get a new one.';
        }
        if ($this->consumed_at !== null) {
            return 'This link is closed - the record it belonged to has been settled.';
        }
        if ($this->isExpired()) {
            return 'This link has expired. Show the QR code again on the computer to get a new one.';
        }
        if ($this->isFull()) {
            return "That is the most files this link can take ({$this->max_files}). Show the QR code again on the computer if you need to add more.";
        }

        return null;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /** @param  Builder<UploadSession>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('files_uploaded', '<', 'max_files');
    }

    /** @param  Builder<UploadSession>  $query */
    public function scopeFor(Builder $query, Model $target): void
    {
        $query->where('attachable_type', $target->getMorphClass())
            ->where('attachable_id', $target->getKey());
    }
}
