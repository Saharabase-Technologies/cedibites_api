<?php

namespace App\Models;

use App\Enums\SmsFailureReason;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of one SMS send attempt. Written by HubtelSmsService,
 * read by SmsHealthService. Never updated.
 */
class SmsDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'notification',
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
            'failure_reason' => SmsFailureReason::class,
            'created_at' => 'datetime',
        ];
    }
}
