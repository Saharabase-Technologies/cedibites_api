<?php

namespace App\Models;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Services\Campaigns\AudienceRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One SMS campaign.
 *
 * The counts and costs on this row are the permanent record. Read the migration
 * before reaching for `sms_delivery_attempts` to report on a campaign — that
 * table is pruned, and this one is not.
 */
class Campaign extends Model
{
    use HasFactory, LogsActivity;

    /**
     * In-memory defaults matching the database's.
     *
     * Without these, a freshly created Campaign carries null for every counter:
     * `create()` only sets what it is given, and column defaults are applied by
     * the database on insert, not read back into the model. The row is correct;
     * the object in hand is not.
     *
     * That gap divided by zero. CampaignResource guarded click-through with
     * `sent_count === 0`, which is false for null, so the guard fell through to
     * `click_count / null` — and only when a short link was attached, because
     * otherwise the check before it short-circuited first. Saving a draft with a
     * link selected returned a 500 while having already written the row, so the
     * operator saw an error, retried, and got duplicate campaigns.
     */
    protected $attributes = [
        'status' => 'draft',
        'recipient_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'delivered_count' => 0,
        'estimated_cost' => 0,
        'segments_per_message' => 1,
    ];

    protected $fillable = [
        'name',
        'message',
        'segment',
        'audience_rules',
        'status',
        'scheduled_for',
        'short_link_id',
        'recipient_count',
        'sent_count',
        'failed_count',
        'delivered_count',
        'batch_ids',
        'delivery_checked_at',
        'estimated_cost',
        'actual_cost',
        'segments_per_message',
        'created_by_user_id',
        'approved_by_user_id',
        'started_at',
        'completed_at',
        'last_tested_at',
        'last_tested_phone',
        'last_tested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'segment' => CampaignSegment::class,
            'audience_rules' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'delivery_checked_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'batch_ids' => 'array',
            'delivered_count' => 'integer',
            'estimated_cost' => 'decimal:4',
            'actual_cost' => 'decimal:4',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'segments_per_message' => 'integer',
        ];
    }

    /**
     * The message text is logged deliberately.
     *
     * A campaign is the company speaking to every customer at once. What was
     * said, and who approved saying it, is the record that matters afterwards.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['name', 'message', 'segment', 'status', 'scheduled_for', 'approved_by_user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }

    /** Who last sent themselves a copy of this before it went out. */
    public function lastTestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_tested_by_user_id');
    }

    /** Per-recipient detail. Prunable — see the migration. */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(SmsDeliveryAttempt::class);
    }

    /**
     * Everything the chunks have accounted for.
     *
     * A campaign is finished when this reaches `recipient_count`, whichever way
     * the individual chunks went.
     */
    public function accountedFor(): int
    {
        return $this->sent_count + $this->failed_count;
    }

    public function isFinished(): bool
    {
        return $this->recipient_count > 0 && $this->accountedFor() >= $this->recipient_count;
    }

    /**
     * The audience as rules, whether it was assembled or picked from a preset.
     *
     * One accessor so nothing downstream has to branch on which it was. A
     * campaign with no rules returns an empty set, which resolves to everybody —
     * and the preset is applied separately by whoever asked.
     */
    public function rules(): AudienceRules
    {
        return AudienceRules::fromArray($this->audience_rules);
    }

    /** Whether this campaign targets an assembled audience rather than a preset. */
    public function hasCustomAudience(): bool
    {
        return ! $this->rules()->isEmpty();
    }
}
