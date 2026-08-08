<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One firing of one rule — including the ones that deliberately sent nothing.
 * See the migration for why the suppressed rows are the point.
 */
class AutomationFire extends Model
{
    use HasFactory;

    /** Why nothing was sent. Null means it went. */
    public const COOLDOWN = 'cooldown';

    public const LOWER_PRIORITY = 'lower_priority';

    public const LIFETIME_CAP = 'lifetime_cap';

    public const NOT_SAMPLED = 'not_sampled';

    public const FEATURE_OFF = 'feature_off';

    public const ALREADY_ANSWERED = 'already_answered';

    protected $fillable = [
        'automation_rule_id',
        'order_id',
        'phone',
        'fired_at',
        'sent_at',
        'suppressed_reason',
        'order_feedback_id',
    ];

    protected function casts(): array
    {
        return [
            'fired_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Actually reached somebody, or is queued to. */
    public function scopeNotSuppressed(Builder $query): Builder
    {
        return $query->whereNull('suppressed_reason');
    }

    public function wasSuppressed(): bool
    {
        return $this->suppressed_reason !== null;
    }
}
