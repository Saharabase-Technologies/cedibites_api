<?php

namespace App\Models;

use App\Enums\SmsFailureReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of one SMS send attempt. Written by HubtelSmsService,
 * read by SmsHealthService. Never updated.
 */
class SmsDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'notification',
        'is_campaign',
        'campaign_id',
        'recipient',
        'succeeded',
        'failure_reason',
        'error_message',
        'message_id',
        // Writable so backfills and tests can place a row in the past; normal
        // sends leave it to the database default.
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'is_campaign' => 'boolean',
            'failure_reason' => SmsFailureReason::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Attempts that say something about the pipe customers depend on.
     *
     * Marketing traffic is excluded deliberately. It arrives in bulk, dwarfs
     * transactional volume, and a campaign failing says nothing about whether
     * an order notification can still get through — which is the only question
     * the health check is asked. Every read behind the health verdict goes
     * through here so the headline number and the failures listed under it
     * cannot disagree.
     *
     * @param  Builder<SmsDeliveryAttempt>  $query
     * @return Builder<SmsDeliveryAttempt>
     */
    public function scopeTransactional(Builder $query): Builder
    {
        return $query->where('is_campaign', false);
    }

    /**
     * The campaign this attempt belongs to, if any.
     *
     * For the delivery-status poll, not for reporting. Campaign totals live on
     * the campaign row precisely because this table is pruned.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
