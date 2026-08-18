<?php

namespace App\Models;

use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffMessageRule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'event',
        'conditions',
        'target',
        'kind',
        'subject',
        'body_template',
        'requires_acknowledgement',
        'allow_custom_reply',
        'quick_replies',
        'sms_fallback_after_minutes',
        'cooldown_minutes',
        'priority',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'event' => StaffMessageEvent::class,
            'kind' => StaffMessageKind::class,
            'conditions' => 'array',
            'target' => 'array',
            'quick_replies' => 'array',
            'requires_acknowledgement' => 'boolean',
            'allow_custom_reply' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function fires(): HasMany
    {
        return $this->hasMany(StaffMessageRuleFire::class, 'rule_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Read one condition with a fallback.
     *
     * Only for conditions the event does not list as required — the required ones
     * are refused at validation precisely so that nothing here has to guess.
     */
    public function condition(string $key, mixed $default = null): mixed
    {
        return data_get($this->conditions, $key, $default);
    }

    /**
     * @return array<int, string>
     */
    public function targets(): array
    {
        return (array) data_get($this->target, 'types', []);
    }

    /**
     * Counts for the rule list.
     *
     * Sent and held-back are shown side by side deliberately. A rule reporting
     * "matched 300, sent 4" reads as the guardrails working; the same rule
     * reporting only "sent 4" reads as broken, and somebody switches it off.
     *
     * @return array<string, int>
     */
    public function fireStats(int $days = 30): array
    {
        $since = now()->subDays($days);
        $fires = $this->fires()->where('fired_at', '>=', $since);

        return [
            'matched' => (clone $fires)->count(),
            'sent' => (clone $fires)->whereNull('suppressed_reason')->count(),
            'held_back' => (clone $fires)->whereNotNull('suppressed_reason')->count(),
        ];
    }
}
