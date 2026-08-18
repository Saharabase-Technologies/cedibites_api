<?php

namespace App\Http\Requests;

use App\Enums\AutomationEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Writing an automation rule.
 *
 * Nothing here switches anything on. A rule is saved inactive unless the caller
 * explicitly asks otherwise, and activating is a separate call — the same
 * separation campaigns make between composing and sending, for the same reason.
 */
class SaveAutomationRuleRequest extends FormRequest
{
    /** Route gates on `manage_campaigns`; the rest is validation. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'event' => [$required, Rule::enum(AutomationEvent::class)],

            // 1,600 characters is ten billed texts. Well past anything sensible,
            // and there so a paste accident cannot become a ten-fold bill on
            // every order for a month.
            'message' => [$required, 'string', 'min:1', 'max:1600'],

            'event_config' => ['sometimes', 'nullable', 'array'],
            'event_config.order_number' => ['nullable', 'integer', 'min:2', 'max:1000'],
            'event_config.gap_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'event_config.minimum_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],

            'short_link_id' => ['sometimes', 'nullable', 'integer', 'exists:short_links,id'],

            /*
             * Bounded deliberately. A delay of zero sends while somebody is
             * still eating; a delay of a fortnight asks about a meal nobody
             * remembers.
             */
            'delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:20160'],

            'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],

            // A rule may ask for a LONGER gap than the house rule. It cannot ask
            // for a shorter one — the model takes the larger of the two — so a
            // low number here is accepted and quietly ignored rather than
            // refused, which would be a confusing error for a harmless input.
            'cooldown_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],

            'max_per_customer' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'sample_rate' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An event without its own setting fires on every qualifying order.
     *
     * "Their Nth order" with no N is not a half-configured rule, it is a rule
     * that matches nothing — or, read the other way by a future change, one that
     * matches everything. Refused here rather than defaulted, because there is
     * no sensible default for which order number somebody meant.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $event = AutomationEvent::tryFrom((string) $this->input('event'));

            if (! $event) {
                return;
            }

            $config = (array) $this->input('event_config', []);

            foreach ($event->configKeys() as $key) {
                if (($config[$key] ?? null) === null || $config[$key] === '') {
                    $v->errors()->add(
                        "event_config.{$key}",
                        $this->missingConfigMessage($event, $key),
                    );
                }
            }
        });
    }

    private function missingConfigMessage(AutomationEvent $event, string $key): string
    {
        return match ($key) {
            'order_number' => 'Say which order number this should fire on — their 3rd, their 10th.',
            'gap_days' => 'Say how long a gap counts as having gone quiet.',
            'minimum_amount' => 'Say how much an order has to be worth.',
            default => 'This event needs a '.str_replace('_', ' ', $key).'.',
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the rule a name so you can find it later.',
            'event.required' => 'Choose what this rule waits for.',
            'message.required' => 'There is no message to send.',
            'message.max' => 'That is longer than ten text messages. Shorten it.',
        ];
    }
}
