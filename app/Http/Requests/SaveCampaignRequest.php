<?php

namespace App\Http\Requests;

use App\Enums\CampaignSegment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
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
