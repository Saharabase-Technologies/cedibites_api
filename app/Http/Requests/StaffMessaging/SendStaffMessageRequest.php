<?php

namespace App\Http\Requests\StaffMessaging;

use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SendStaffMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already carries the permission middleware; this is the
        // second lock on the same door.
        return $this->user()?->can('staff_messages.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(StaffMessageKind::composableByAdmin())],
            'subject' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:4000'],

            // A path this API issued from the upload endpoint, never a URL the
            // caller chose — see the migration note. Validated as a path under
            // our own prefix so a message cannot be pointed at somebody else's
            // image and rendered inside our chrome.
            'image_path' => ['nullable', 'string', 'max:255', 'starts_with:staff-messages/'],

            // ─── Release walkthroughs ─────────────────────────────────────
            // A stable name for this release, so the same announcement cannot
            // go out twice. On a kind that interrupts every sign-in until it is
            // acknowledged, a duplicate is not a cosmetic problem.
            'release_key' => ['nullable', 'string', 'max:100', 'unique:staff_messages,release_key'],

            'steps' => ['sometimes', 'array', 'max:12'],
            'steps.*.title' => ['nullable', 'string', 'max:150'],
            'steps.*.body' => ['required', 'string', 'max:2000'],
            'steps.*.image_path' => ['nullable', 'string', 'max:255', 'starts_with:staff-messages/'],

            'audience' => ['required', 'array'],
            'audience.everyone' => ['sometimes', 'boolean'],
            'audience.roles' => ['sometimes', 'array'],
            'audience.roles.*' => [Rule::in(RoleEnum::values())],
            'audience.branch_ids' => ['sometimes', 'array'],
            'audience.branch_ids.*' => ['integer', 'exists:branches,id'],
            'audience.user_ids' => ['sometimes', 'array'],
            'audience.user_ids.*' => ['integer', 'exists:users,id'],
            'audience.include_company_wide' => ['sometimes', 'boolean'],

            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'allow_custom_reply' => ['sometimes', 'boolean'],

            'quick_replies' => ['sometimes', 'array', 'max:5'],
            'quick_replies.*' => ['string', 'max:40'],

            'sms_fallback_after_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // A walkthrough with nothing to walk through renders as an empty
            // modal that still has to be acknowledged.
            if ($this->input('kind') === StaffMessageKind::Release->value
                && count((array) $this->input('steps', [])) === 0) {
                $validator->errors()->add('steps', 'A release needs at least one slide.');
            }

            // Slides on any other kind would be dropped silently on save, which
            // reads to the sender as having been sent.
            if ($this->input('kind') !== StaffMessageKind::Release->value
                && count((array) $this->input('steps', [])) > 0) {
                $validator->errors()->add('steps', 'Only a release can carry slides.');
            }

            $audience = (array) $this->input('audience', []);

            $hasSelection = data_get($audience, 'everyone')
                || data_get($audience, 'roles')
                || data_get($audience, 'branch_ids')
                || data_get($audience, 'user_ids');

            // An empty audience is refused rather than treated as "everyone".
            // The tempting default is the dangerous one: a mis-click that sends
            // a caution to every member of staff in the company is not
            // recoverable, and the message will already be on their phones
            // before anybody notices.
            if (! $hasSelection) {
                $validator->errors()->add('audience', 'Choose who this goes to.');
            }

            // A message that requires an acknowledgement but offers no way to
            // give one is a dead end on the recipient's screen.
            if ($this->boolean('requires_acknowledgement') && $this->input('kind') === StaffMessageKind::Notice->value) {
                $validator->errors()->add(
                    'requires_acknowledgement',
                    'A notice sits in the bell and cannot ask for an acknowledgement. Send it as a caution instead.',
                );
            }
        });
    }
}
