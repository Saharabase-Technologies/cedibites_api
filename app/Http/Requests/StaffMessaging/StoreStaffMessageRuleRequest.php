<?php

namespace App\Http\Requests\StaffMessaging;

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageEvent;
use App\Enums\StaffMessageKind;
use App\Enums\StaffMessageTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffMessageRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('staff_messages.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'event' => ['required', Rule::in(StaffMessageEvent::values())],

            'conditions' => ['sometimes', 'array'],
            'conditions.status' => ['sometimes', 'string', 'max:40'],
            'conditions.minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'conditions.hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'conditions.threshold' => ['sometimes', 'integer', 'min:2', 'max:200'],
            'conditions.window_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],

            'target' => ['required', 'array'],
            'target.types' => ['required', 'array', 'min:1'],
            'target.types.*' => [Rule::in(StaffMessageTarget::values())],
            'target.roles' => ['sometimes', 'array'],
            'target.roles.*' => [Rule::in(RoleEnum::values())],

            'kind' => ['required', Rule::in(StaffMessageKind::composableByAdmin())],
            'subject' => ['nullable', 'string', 'max:150'],
            'body_template' => ['required', 'string', 'max:2000'],

            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'allow_custom_reply' => ['sometimes', 'boolean'],
            'quick_replies' => ['sometimes', 'array', 'max:5'],
            'quick_replies.*' => ['string', 'max:40'],
            'sms_fallback_after_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],

            'cooldown_minutes' => ['sometimes', 'integer', 'min:1', 'max:20160'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],

            // Deliberately absent: `is_active`. Saving a rule and switching it on
            // are two separate calls — see the toggle endpoint. A rule that goes
            // live the moment it is saved cannot be dry-run first, and dry-running
            // first is the whole discipline.
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $event = StaffMessageEvent::tryFrom((string) $this->input('event'));

            if (! $event) {
                return;
            }

            // An event whose settings are missing is REFUSED, never defaulted.
            // The tempting default is always the permissive one — zero minutes,
            // no threshold — and it matches everything.
            foreach ($event->requiredConditions() as $key) {
                if ($this->input("conditions.{$key}") === null) {
                    $validator->errors()->add(
                        "conditions.{$key}",
                        "\"{$event->label()}\" needs a value for {$key}.",
                    );
                }
            }

            $types = (array) $this->input('target.types', []);

            if (in_array(StaffMessageTarget::Roles->value, $types, true) && ! $this->input('target.roles')) {
                $validator->errors()->add('target.roles', 'Pick at least one role.');
            }
        });
    }
}
