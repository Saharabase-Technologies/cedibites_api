<?php

namespace App\Models;

use App\Enums\StaffMessageKind;
use App\Enums\StaffMessageTrigger;
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
        'release_key',
        'subject',
        'body',
        'image_path',
        'audience',
        'requires_acknowledgement',
        'allow_custom_reply',
        'quick_replies',
        'sms_fallback_after_minutes',
        'expires_at',
        'visible_from',
        'display_trigger',
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
            'visible_from' => 'datetime',
            'display_trigger' => StaffMessageTrigger::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Public URL for the attached image, or null.
     *
     * Built from the disk at read time rather than stored, so relocating storage
     * does not strand every message that was ever sent.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->image_path)
            : null;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * The slides, in the order an admin arranged them.
     *
     * Ordered here rather than at every call site: a walkthrough shown out of
     * order is worse than no walkthrough, and the ordering is not something a
     * caller should be able to forget.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(StaffMessageStep::class)->orderBy('position');
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
            })
            // The start of the window, enforced here rather than in the clients.
            // A message held until Monday must be invisible to the inbox, the
            // bell count and the SMS escalation alike, and the only way to get
            // that for free everywhere is to keep it out of `live`.
            ->where(function (Builder $q) {
                $q->whereNull('visible_from')->orWhere('visible_from', '<=', now());
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
            // How many people it actually reached. On a kind nobody opens from
            // the bell this is the only honest reach figure; `read` stays at
            // zero for a walkthrough until somebody finishes it.
            'shown' => (clone $recipients)->whereNotNull('shown_at')->count(),
            'read' => (clone $recipients)->whereNotNull('read_at')->count(),
            'acknowledged' => $this->requires_acknowledgement
                ? (clone $recipients)->whereNotNull('acknowledged_at')->count()
                : 0,
            'replied' => (clone $recipients)->whereNotNull('replied_at')->count(),
            'sms_sent' => (clone $recipients)->whereNotNull('sms_sent_at')->count(),
        ];
    }
}
