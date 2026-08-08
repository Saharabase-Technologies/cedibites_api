<?php

namespace App\Models;

use App\Enums\AutomationEvent;
use App\Services\Campaigns\AudienceRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A message waiting for something to happen. See the migration for why this is
 * an automation rule rather than a feedback rule.
 */
class AutomationRule extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'event',
        'event_config',
        'audience_rules',
        'message',
        'short_link_id',
        'delay_minutes',
        'is_active',
        'priority',
        'cooldown_days',
        'max_per_customer',
        'sample_rate',
        'created_by_user_id',
    ];

    protected $attributes = [
        'delay_minutes' => 180,
        'is_active' => false,
        'priority' => 100,
        'sample_rate' => 100,
    ];

    protected function casts(): array
    {
        return [
            'event' => AutomationEvent::class,
            'event_config' => 'array',
            'audience_rules' => 'array',
            'is_active' => 'boolean',
            'delay_minutes' => 'integer',
            'priority' => 'integer',
            'sample_rate' => 'integer',
        ];
    }

    /**
     * Switching a rule on or off is the act worth recording — it is the moment a
     * rule starts or stops texting customers, and the only question anybody asks
     * afterwards is who did it and when.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('automation')
            ->logOnly(['name', 'event', 'is_active', 'message', 'sample_rate', 'cooldown_days'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function fires(): HasMany
    {
        return $this->hasMany(AutomationFire::class);
    }

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** In the order the evaluator considers them — first match wins. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('priority')->orderBy('id');
    }

    /** The audience conditions, in the same language the campaign builder speaks. */
    public function rules(): AudienceRules
    {
        return AudienceRules::fromArray($this->audience_rules ?? []);
    }

    /** One of this event's own settings. */
    public function config(string $key, mixed $default = null): mixed
    {
        return ($this->event_config ?? [])[$key] ?? $default;
    }

    /**
     * The gap this rule wants, which can be longer than the house rule but never
     * shorter.
     *
     * A rule cannot opt out of the global cooldown. That is what stops three
     * separately-reasonable rules from adding up to three texts in an afternoon,
     * and letting one of them lower the bar would undo it.
     */
    public function effectiveCooldownDays(): int
    {
        $global = (int) config('automation.cooldown_days', 3);

        return max($global, (int) ($this->cooldown_days ?? 0));
    }
}
