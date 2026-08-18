<?php

namespace App\Models;

use App\Enums\StaffMessageKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sender_user_id',
        'rule_id',
        'parent_id',
        'kind',
        'subject',
        'body',
        'audience',
        'requires_acknowledgement',
        'allow_custom_reply',
        'quick_replies',
        'sms_fallback_after_minutes',
        'expires_at',
        'sent_at',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'kind' => StaffMessageKind::class,
            'audience' => 'array',
            'quick_replies' => 'array',
            'requires_acknowledgement' => 'boolean',
            'allow_custom_reply' => 'boolean',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(StaffMessageRule::class, 'rule_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(StaffMessageRecipient::class);
    }

    /** Sent, and not past its own expiry. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isDraft(): bool
    {
        return $this->sent_at === null;
    }

    /**
     * Delivery figures for the sender's screen.
     *
     * Acknowledged is reported against the number of people who were required to
     * acknowledge, not against everyone — on a message that asks for no
     * acknowledgement the ratio would otherwise read 0 of 40 forever and look
     * like a fault.
     *
     * @return array<string, int>
     */
    public function deliveryStats(): array
    {
        $recipients = $this->recipients();

        return [
            'total' => (clone $recipients)->count(),
            'read' => (clone $recipients)->whereNotNull('read_at')->count(),
            'acknowledged' => $this->requires_acknowledgement
                ? (clone $recipients)->whereNotNull('acknowledged_at')->count()
                : 0,
            'replied' => (clone $recipients)->whereNotNull('replied_at')->count(),
            'sms_sent' => (clone $recipients)->whereNotNull('sms_sent_at')->count(),
        ];
    }
}
