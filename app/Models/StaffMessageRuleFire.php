<?php

namespace App\Models;

use App\Enums\StaffMessageSuppression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffMessageRuleFire extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_id',
        'subject_type',
        'subject_id',
        'user_id',
        'staff_message_id',
        'suppressed_reason',
        'fired_at',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_reason' => StaffMessageSuppression::class,
            'fired_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(StaffMessageRule::class, 'rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(StaffMessage::class, 'staff_message_id');
    }

    public function wasSent(): bool
    {
        return $this->suppressed_reason === null;
    }
}
