<?php

namespace App\Http\Resources;

use App\Models\AutomationFire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AutomationRule */
class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sent = (int) ($this->fires_sent_count ?? 0);
        $answered = (int) ($this->fires_answered_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,

            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'event_description' => $this->event->description(),
            'event_config' => $this->event_config ?? [],
            'required_config' => $this->event->configKeys(),

            'audience_rules' => $this->audience_rules,
            'audience_description' => $this->rules()->describe(),

            'message' => $this->message,
            'short_link' => $this->whenLoaded('shortLink', fn () => $this->shortLink ? [
                'id' => $this->shortLink->id,
                'label' => $this->shortLink->label,
                'sms_url' => $this->shortLink->smsUrl(),
            ] : null),

            'delay_minutes' => $this->delay_minutes,
            'is_active' => $this->is_active,
            'priority' => $this->priority,

            // What the rule asked for, and what it will actually get. They
            // differ when a rule asks for a shorter gap than the house rule,
            // which is accepted and overridden rather than refused.
            'cooldown_days' => $this->cooldown_days,
            'effective_cooldown_days' => $this->effectiveCooldownDays(),

            'max_per_customer' => $this->max_per_customer,
            'sample_rate' => $this->sample_rate,

            /*
             * What it has actually done.
             *
             * `matched` counts every firing including the suppressed ones,
             * because the gap between matched and sent IS the guardrails doing
             * their job. A rule showing 400 matched and 12 sent is working
             * correctly, and showing only the 12 would read as a rule that
             * barely fires.
             */
            'matched_count' => (int) ($this->fires_count ?? 0),
            'sent_count' => $sent,
            'answered_count' => $answered,

            // Null rather than zero until something has been sent — a response
            // rate of 0% and "nothing has gone out yet" are different facts.
            'response_rate' => $sent > 0 ? round($answered / $sent * 100, 1) : null,

            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** The counts every listing needs, as one query rather than N. */
    public static function withCounts($query)
    {
        return $query->withCount([
            'fires',
            'fires as fires_sent_count' => fn ($q) => $q->whereNotNull('sent_at'),
            'fires as fires_answered_count' => fn ($q) => $q->whereNotNull('order_feedback_id'),
        ]);
    }

    /** Why the suppressed firings were suppressed, for one rule. */
    public static function suppressionBreakdown(int $ruleId): array
    {
        return AutomationFire::where('automation_rule_id', $ruleId)
            ->whereNotNull('suppressed_reason')
            ->selectRaw('suppressed_reason, count(*) as total')
            ->groupBy('suppressed_reason')
            ->pluck('total', 'suppressed_reason')
            ->all();
    }
}
