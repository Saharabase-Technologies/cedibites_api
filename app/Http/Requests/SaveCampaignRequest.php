<?php

namespace App\Http\Requests;

use App\Enums\CampaignSegment;
use App\Enums\ContactSource;
use App\Enums\GhanaNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Composing a campaign, and editing a draft.
 *
 * Nothing here sends anything. A campaign is inert until somebody approves it,
 * which is a separate call with a separate confirmation — see
 * CampaignController::send().
 */
class SaveCampaignRequest extends FormRequest
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

            // 1,600 characters is ten billed segments. Well past anything
            // sensible, and there purely so a paste accident cannot become a
            // ten-fold bill.
            'message' => [$required, 'string', 'min:1', 'max:1600'],

            'segment' => [$required, Rule::enum(CampaignSegment::class)],

            'short_link_id' => ['sometimes', 'nullable', 'integer', 'exists:short_links,id'],

            'scheduled_for' => ['sometimes', 'nullable', 'date', 'after:now'],

            ...self::audienceRules(),
        ];
    }

    /**
     * The assembled audience, when the operator built one instead of picking a
     * preset. Every key is optional — an empty rule set means everybody, and
     * each rule that is set can only narrow.
     *
     * Bounded on purpose. The windows and counts are not open-ended integers:
     * a mistyped `min_orders` of 100000 silently produces an empty audience,
     * and a mistyped `ordered_within_days` of 100000 silently produces the
     * whole list. Both are worth refusing rather than resolving.
     */
    public static function audienceRules(): array
    {
        return [
            'audience_rules' => ['sometimes', 'nullable', 'array'],

            /*
             * Which pools to draw from. Absent means customers only — the one
             * rule here that can WIDEN an audience rather than narrow it, which
             * is why it has to be asked for explicitly and why it is described
             * first in the review step.
             */
            'audience_rules.sources' => ['nullable', 'array', 'max:2'],
            'audience_rules.sources.*' => [Rule::enum(ContactSource::class)],

            'audience_rules.ordered_within_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'audience_rules.not_ordered_for_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'audience_rules.ordered_after' => ['nullable', 'date'],
            'audience_rules.ordered_before' => ['nullable', 'date'],

            // The receipt line — what was actually bought. The item-level filter
            // below is the broader net that still matches when an option has
            // since been deleted off the menu.
            'audience_rules.menu_item_option_ids' => ['nullable', 'array', 'max:100'],
            'audience_rules.menu_item_option_ids.*' => ['integer', 'exists:menu_item_options,id'],

            'audience_rules.menu_item_ids' => ['nullable', 'array', 'max:50'],
            'audience_rules.menu_item_ids.*' => ['integer', 'exists:menu_items,id'],

            'audience_rules.branch_ids' => ['nullable', 'array', 'max:50'],
            'audience_rules.branch_ids.*' => ['integer', 'exists:branches,id'],

            'audience_rules.primary_branch_ids' => ['nullable', 'array', 'max:50'],
            'audience_rules.primary_branch_ids.*' => ['integer', 'exists:branches,id'],
            'audience_rules.primary_branch_min_orders' => ['nullable', 'integer', 'min:1', 'max:10000'],

            'audience_rules.only_branch_ids' => ['nullable', 'array', 'max:50'],
            'audience_rules.only_branch_ids.*' => ['integer', 'exists:branches,id'],

            'audience_rules.networks' => ['nullable', 'array', 'max:4'],
            'audience_rules.networks.*' => [Rule::enum(GhanaNetwork::class)],

            'audience_rules.min_orders' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'audience_rules.max_orders' => ['nullable', 'integer', 'min:1', 'max:10000'],

            'audience_rules.min_spend' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'audience_rules.max_spend' => ['nullable', 'numeric', 'min:0', 'max:1000000'],

            'audience_rules.hour_from' => ['nullable', 'integer', 'min:0', 'max:23'],
            'audience_rules.hour_to' => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }

    /**
     * Catch ranges that can never match anybody.
     *
     * A backwards range is not a validation technicality — it resolves silently
     * to an empty audience, and the operator finds out only when the send is
     * refused for having nobody in it. Saying "you have asked for at least 5 and
     * at most 2" is a great deal more use than "nobody is in that segment".
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $rules = (array) $this->input('audience_rules', []);

            $pairs = [
                ['min_orders', 'max_orders', 'audience_rules.max_orders',
                    'The largest number of orders has to be at least as big as the smallest.'],
                ['min_spend', 'max_spend', 'audience_rules.max_spend',
                    'The most spent has to be at least as much as the least.'],
                ['hour_from', 'hour_to', 'audience_rules.hour_to',
                    'The end of the time window has to be after the start.'],
            ];

            foreach ($pairs as [$lowKey, $highKey, $field, $message]) {
                $low = $rules[$lowKey] ?? null;
                $high = $rules[$highKey] ?? null;

                if ($low !== null && $high !== null && (float) $high < (float) $low) {
                    $v->errors()->add($field, $message);
                }
            }

            $after = $rules['ordered_after'] ?? null;
            $before = $rules['ordered_before'] ?? null;

            if ($after && $before && strtotime((string) $before) < strtotime((string) $after)) {
                $v->errors()->add('audience_rules.ordered_before', 'The end date has to be after the start date.');
            }

            // "Ordered in the last 7 days" AND "has not ordered for 30 days" is
            // nobody, always. Two rules that each look sensible alone.
            $within = $rules['ordered_within_days'] ?? null;
            $notFor = $rules['not_ordered_for_days'] ?? null;

            if ($within !== null && $notFor !== null && (int) $notFor >= (int) $within) {
                $v->errors()->add(
                    'audience_rules.not_ordered_for_days',
                    'Nobody can have ordered within the last '.(int) $within
                        .' days and also not have ordered for '.(int) $notFor.' days.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the campaign a name so you can find it later.',
            'message.required' => 'There is no message to send.',
            'message.max' => 'That is longer than ten text messages. Shorten it.',
            'segment.required' => 'Choose who this goes to.',
            'scheduled_for.after' => 'A time in the past cannot be scheduled.',
        ];
    }
}
