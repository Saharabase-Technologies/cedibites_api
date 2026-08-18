<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffMessageRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_message_id',
        'user_id',
        'branch_id',
        'delivered_at',
        'read_at',
        'acknowledged_at',
        'quick_reply',
        'reply_body',
        'replied_at',
        'sms_sent_at',
        'sms_status',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'replied_at' => 'datetime',
            'sms_sent_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Stamp read, once.
     *
     * Guarded rather than overwritten: the first time somebody saw a caution is
     * the fact worth keeping, and re-opening the message later must not move it.
     */
    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * Acknowledging implies having read it.
     *
     * A push notification can be acknowledged straight from the lock screen
     * without the inbox ever being opened, which would otherwise leave a row
     * acknowledged but unread — a state the sender's screen cannot sensibly
     * display.
     */
    public function markAcknowledged(): void
    {
        $this->forceFill([
            'read_at' => $this->read_at ?? now(),
            'acknowledged_at' => $this->acknowledged_at ?? now(),
        ])->save();
    }
}
