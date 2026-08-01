<?php

namespace App\Http\Requests;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentLink;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The joining form, as filled in by a new member of staff.
 *
 * These are people who have already been taken on; the form is how their details
 * reach the system, not a job application. It mirrors CreateEmployeeRequest minus
 * everything that is not theirs to decide: no role, no branch, no status, no
 * password_mode. The role is chosen by whoever checks the details, and the branch
 * comes from the link.
 */
class SubmitRecruitmentApplicationRequest extends FormRequest
{
    private ?RecruitmentLink $link = null;

    /**
     * Public by design — the token in the URL is the only credential. What is
     * checked here is the link itself.
     *
     * It has to happen at this stage rather than in the controller: a form
     * request validates before the action runs, so a closed link posted with a
     * typo in the phone number would answer 422 about the phone and say nothing
     * about the link being shut.
     */
    public function authorize(): bool
    {
        return $this->link() !== null;
    }

    /**
     * One answer for "never existed" and "expired", and a 404 rather than a 403.
     *
     * Telling those apart would turn this endpoint into a way of testing whether
     * a token is real, which is the whole of the token's value.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            response()->error('This link has expired.', 404)
        );
    }

    /** The open link this form was posted to, or null. Resolved once. */
    public function link(): ?RecruitmentLink
    {
        if ($this->link) {
            return $this->link;
        }

        $link = RecruitmentLink::where('token', (string) $this->route('token'))->first();

        return $this->link = ($link?->isOpen() ? $link : null);
    }

    protected function prepareForValidation(): void
    {
        // Canonicalise before the duplicate checks run, not after — they compare
        // against users.phone, which is stored normalised. `024…` and `+233…`
        // are the same phone to everyone except a string comparison.
        if ($this->filled('phone')) {
            $this->merge(['phone' => User::normalizePhone($this->input('phone')) ?? $this->input('phone')]);
        }

        if ($this->filled('phone_confirmation')) {
            $this->merge([
                'phone_confirmation' => User::normalizePhone($this->input('phone_confirmation'))
                    ?? $this->input('phone_confirmation'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            // Typed twice. There is no OTP on this form by decision — verifying
            // a number before anyone is hired is friction for no gain, since a
            // bad number costs nothing until it is approved. The confirm box is
            // what catches the ordinary slip.
            'phone' => [
                'required',
                'string',
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $existing = User::where('phone', $value)->first();

                    // A phone belonging to a plain customer is fine and expected
                    // — that is the reuse path. Only an existing staff account
                    // is a collision.
                    if ($existing && $existing->employee) {
                        $fail('This phone number already has a staff account. Speak to your manager — you do not need to fill this in.');
                    }

                    if ($this->hasOpenApplication($value)) {
                        $fail('We already have your details. There is nothing more for you to do.');
                    }
                },
            ],
            'phone_confirmation' => ['required', 'string'],

            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],

            // Chosen by the applicant, weeks before their first shift. Confirmed,
            // because there is no admin who can read it back to them.
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],

            // HR information — all optional. Nothing here blocks an application.
            //
            // No SSNIT and no TIN: an applicant is not on payroll and often does
            // not have them yet. They belong to the staff editor, after the hire.
            'ghana_card_id' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:255'],

            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Whether this phone already has an application waiting on this posting.
     * Backs up the partial unique index so the applicant gets a sentence rather
     * than a 500.
     */
    private function hasOpenApplication(string $phone): bool
    {
        $link = $this->link();

        if (! $link) {
            return false;
        }

        return RecruitmentApplication::query()
            ->where('recruitment_link_id', $link->id)
            ->where('phone', $phone)
            ->pending()
            ->exists();
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please tell us your name.',
            'phone.required' => 'A phone number is required.',
            'phone.confirmed' => 'The two phone numbers do not match.',
            'phone_confirmation.required' => 'Please type your phone number a second time.',
            'email.unique' => 'This email is already registered.',
            'password.required' => 'Please choose a password.',
            'password.min' => 'Your password must be at least 8 characters.',
            'password.confirmed' => 'The two passwords do not match.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ];
    }
}
