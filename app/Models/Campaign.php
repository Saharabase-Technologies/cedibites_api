<?php

namespace App\Models;

use App\Enums\CampaignSegment;
use App\Enums\CampaignStatus;
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

    protected $fillable = [
        'name',
        'message',
        'segment',
        'status',
        'scheduled_for',
        'short_link_id',
        'recipient_count',
        'sent_count',
        'failed_count',
        'estimated_cost',
        'actual_cost',
        'segments_per_message',
        'created_by_user_id',
        'approved_by_user_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'segment' => CampaignSegment::class,
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
}
