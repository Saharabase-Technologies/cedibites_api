<?php

namespace App\Http\Requests;

use App\Enums\RecruitmentLinkKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Opening a recruitment posting.
 *
 * The kind decides everything downstream, so it is the one field that has to be
 * right: a branch link stamps a branch on whoever is approved, a call-centre
 * link deliberately stamps nothing.
 */
class CreateRecruitmentLinkRequest extends FormRequest
{
    /** Route already gates on `manage_employees`; the rest is validation. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A branch sent alongside a call-centre link is dropped rather than
        // refused, matching how the staff editor treats a branch sent for a
        // company-wide role. The client is not making a mistake the operator can
        // correct — it is asking for something that does not exist.
        if ($this->input('kind') === RecruitmentLinkKind::CallCenter->value) {
            $this->merge(['branch_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(RecruitmentLinkKind::class)],

            'branch_id' => [
                Rule::requiredIf(fn () => $this->input('kind') === RecruitmentLinkKind::Branch->value),
                'nullable',
                'integer',
                'exists:branches,id',
            ],

            'label' => ['nullable', 'string', 'max:255'],

            // The only thing that closes a link. There is no kill switch and no
            // headcount cap by decision, so this is not optional and it is not
            // allowed to be absurd — a link open for years is a link nobody
            // remembers sending.
            'expires_at' => ['required', 'date', 'after:now', 'before:'.now()->addYear()->toDateString()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $user = $this->user();

            if (! $user || $v->errors()->isNotEmpty()) {
                return;
            }

            $kind = RecruitmentLinkKind::tryFrom((string) $this->input('kind'));

            // A manager hires into the branch they run and nowhere else. Anyone
            // company-wide (admin, tech_admin) may open either kind.
            if ($user->isCompanyWide() || $user->hasAnyRole(['admin', 'tech_admin'])) {
                return;
            }

            if ($kind === RecruitmentLinkKind::CallCenter) {
                $v->errors()->add('kind', 'Only an administrator can recruit for the call centre.');

                return;
            }

            $ownBranches = $user->employee
                ? $user->employee->branches()->pluck('branches.id')->all()
                : [];

            if (! in_array((int) $this->input('branch_id'), $ownBranches, true)) {
                $v->errors()->add('branch_id', 'You can only recruit for a branch you are assigned to.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'A branch link needs a branch.',
            'expires_at.required' => 'Give the link a closing date — it is the only thing that shuts it.',
            'expires_at.after' => 'The closing date must be in the future.',
            'expires_at.before' => 'Keep the closing date within a year.',
        ];
    }
}
