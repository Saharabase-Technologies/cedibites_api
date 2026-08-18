<?php

namespace App\Http\Resources\StaffMessaging;

use App\Services\Platform\RuntimeSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\StaffMessageRule
 */
class StaffMessageRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'conditions' => $this->conditions ?? [],
            'target' => $this->target ?? [],
            'kind' => $this->kind->value,
            'subject' => $this->subject,
            'body_template' => $this->body_template,
            'merge_fields' => array_merge(['name', 'first_name', 'branch'], $this->event->mergeFields()),
            'requires_acknowledgement' => $this->requires_acknowledgement,
            'allow_custom_reply' => $this->allow_custom_reply,
            'quick_replies' => $this->quick_replies ?? [],
            'sms_fallback_after_minutes' => $this->sms_fallback_after_minutes,
            'cooldown_minutes' => $this->cooldown_minutes,
            'priority' => $this->priority,
            'is_active' => $this->is_active,

            // Reported beside the rule so a screen full of live rules sending
            // nothing is explicable. Without it the only available conclusion is
            // that the rules are broken.
            'automation_enabled' => (bool) app(RuntimeSettings::class)->get('staff_messaging.automation_enabled'),

            'stats' => $this->fireStats(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
