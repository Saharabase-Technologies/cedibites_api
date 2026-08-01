<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a posting that is already out there.
 *
 * Only two things can change: what you call it, and when it closes.
 *
 * **The kind and the branch are deliberately not editable.** People have already
 * been sent this URL and some may have already applied through it. Switching a
 * Lakeside posting to Ashaiman would move every pending applicant to a branch
 * they never applied to, and switching a branch posting to the call centre would
 * leave applicants awaiting a role their form was never for. Neither failure
 * looks wrong on screen. If the posting was wrong, delete it and open another.
 */
class UpdateRecruitmentLinkRequest extends FormRequest
{
    /** Route gates on `manage_employees`; the controller scopes by branch. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],

            // No `after:now` here, unlike creating one. A date in the past is
            // how a link is shut — it is the close button, and refusing it would
            // leave no way to stop a posting you have changed your mind about.
            'expires_at' => ['sometimes', 'required', 'date', 'before:'.now()->addYear()->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'expires_at.required' => 'A posting needs a closing date.',
            'expires_at.before' => 'Keep the closing date within a year.',
        ];
    }
}
