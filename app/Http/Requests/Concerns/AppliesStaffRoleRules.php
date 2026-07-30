<?php

namespace App\Http\Requests\Concerns;

use App\Enums\BranchRule;
use App\Enums\Role;
use App\Models\Employee;
use Illuminate\Validation\Validator;

/**
 * The role rules, applied once, for both hiring and editing.
 *
 * Two things were being decided by whoever happened to be writing the form:
 * which roles a staff editor may hand out, and how many branches each role
 * takes. Both now come from App\Enums\Role and are enforced here so the API
 * agrees with itself no matter which client is calling.
 *
 * Deliberately lenient in one direction: branches sent for a role that has none
 * are dropped rather than refused. An older client that always sends a branch is
 * not making a mistake an operator can fix — the field should not have been
 * there — so the request succeeds with the branch ignored. A real contradiction
 * (two branches for a manager, a role nobody may grant) still fails loudly.
 */
trait AppliesStaffRoleRules
{
    /** The employee being edited, or null when hiring. */
    abstract protected function targetEmployee(): ?Employee;

    /**
     * The role this request will leave the account holding.
     *
     * On an edit that does not touch the role, that is the role the account
     * already has — the branch rules still apply to it, or a manager could be
     * given a second branch simply by not resending `role`.
     */
    protected function resolvedRole(): ?Role
    {
        $submitted = $this->input('role');

        if (is_string($submitted) && ($role = Role::tryFrom($submitted))) {
            return $role;
        }

        if ($submitted !== null) {
            // Present but not a valid role — let the enum rule report it.
            return null;
        }

        $current = $this->targetEmployee()?->user?->getRoleNames()->first();

        return is_string($current) ? Role::tryFrom($current) : null;
    }

    /**
     * Strip what is no longer accepted and normalise what the role decides.
     *
     * `permissions` is dropped rather than rejected. It used to write direct
     * grants on top of the role, which is how a manager kept getting
     * `manage_employees` back after it was taken away: the staff editor read a
     * person's effective permissions, showed them as checkboxes, and wrote the
     * lot back as direct grants on save. Permissions come from the role now, so
     * the field has no meaning — and refusing it would only break the older
     * client that still sends it on every save.
     */
    protected function applyRoleRules(): void
    {
        if ($this->has('permissions')) {
            $this->request->remove('permissions');
        }

        if ($this->resolvedRole()?->branchRule() === BranchRule::None) {
            $this->merge(['branch_ids' => []]);
        }
    }

    /**
     * Branch validation for the resolved role.
     *
     * @return array<int, mixed>
     */
    protected function branchRules(bool $required): array
    {
        $rule = $this->resolvedRole()?->branchRule();

        return match ($rule) {
            BranchRule::None => ['nullable', 'array'],
            BranchRule::ExactlyOne => [$required ? 'required' : 'sometimes', 'array', 'size:1'],
            // Unknown role: fall back to the loosest shape and let the `role`
            // rule produce the real error rather than piling a second one on.
            default => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
        };
    }

    /**
     * Catch the case the field rules cannot see: a role change that leaves the
     * account holding a branch count its new role does not allow, without the
     * caller resending `branch_ids`. Promoting a rider who covers three branches
     * to branch manager has to say which one.
     */
    protected function validateBranchCardinality(Validator $validator): void
    {
        $rule = $this->resolvedRole()?->branchRule();

        if ($rule === null || $rule === BranchRule::None || $this->has('branch_ids')) {
            return;
        }

        $employee = $this->targetEmployee();

        if (! $employee) {
            return;
        }

        $current = $employee->branches()->count();

        if ($rule === BranchRule::ExactlyOne && $current !== 1) {
            $validator->errors()->add(
                'branch_ids',
                'This role is assigned exactly one branch. Choose the branch this person works at.',
            );

            return;
        }

        if ($rule === BranchRule::OneOrMore && $current === 0) {
            $validator->errors()->add(
                'branch_ids',
                'This role must be assigned at least one branch.',
            );
        }
    }
}
