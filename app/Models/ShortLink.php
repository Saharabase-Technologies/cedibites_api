<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A short link, and the two forms of its URL.
 *
 * Both are built from config rather than stored, so a domain change is an
 * environment variable. The distinction between them is not cosmetic:
 *
 *   url()    https://cedibites.com/r/A7X9Kp   — for clicking out of the admin
 *   smsUrl()        cedibites.com/r/A7X9Kp   — for putting in a message
 *
 * Never write the scheme into an SMS. Handsets auto-link a bare domain, so
 * `https://` costs eight characters and buys nothing — and eight characters is
 * the whole margin on a message sitting at 161.
 */
class ShortLink extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'token',
        'label',
        'target_url',
        'created_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'click_count' => 'integer',
        ];
    }

    /**
     * Audit material, not housekeeping.
     *
     * Anyone who can create a link can point our branded domain at a phishing
     * page wearing our name, and repointing an existing one does it to a URL
     * already in customers' phones. The target is logged on every change so
     * there is a record of who aimed it where.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['token', 'label', 'target_url', 'expires_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Base62 by way of Str::random, which is alphanumeric only.
     *
     * Random, never sequential — see the migration for why.
     */
    public static function generateToken(?int $length = null): string
    {
        return Str::random($length ?? (int) config('short_links.token_length', 6));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Whether this link will still take somebody somewhere. */
    public function isActive(): bool
    {
        return ! $this->isExpired();
    }

    /** The absolute URL, scheme included. For the admin, not for a message. */
    public function url(): string
    {
        return rtrim((string) config('short_links.base_url'), '/')."/r/{$this->token}";
    }

    /** The same link with the scheme stripped, which is what goes in an SMS. */
    public function smsUrl(): string
    {
        return preg_replace('#^https?://#', '', $this->url());
    }

    /**
     * Whether this points somewhere that is not ours.
     *
     * Surfaced in the admin list. A branded short domain has better SMS
     * deliverability than bit.ly precisely because carriers trust it, which is
     * exactly what makes an unnoticed external target expensive.
     */
    public function isExternal(): bool
    {
        $host = strtolower((string) parse_url($this->target_url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        return ! in_array($host, array_map('strtolower', (array) config('short_links.own_hosts', [])), true);
    }
}
