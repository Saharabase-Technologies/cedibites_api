<?php

namespace App\Domain\Staff;

use App\Enums\EmployeeStatus;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one way a staff account comes into existence.
 *
 * Lifted out of EmployeeController::store so that hiring by hand and hiring
 * through the recruitment form take the identical path. Every detail below is a
 * bug that was found and fixed once already, and a second copy would drift away
 * from them one at a time:
 *
 *  - an existing user is reused by phone, so bringing a customer onto staff does
 *    not create a second person out of the same number;
 *  - roles are synced, not assigned, because a rehire used to end up holding two
 *    while every screen reads roles->first();
 *  - direct permissions are cleared, because permissions come from the role and
 *    nothing else;
 *  - employee_no is derived under a row lock, because two admins hiring at once
 *    used to collide;
 *  - branch_ids arrives already normalised to [] for company-wide roles, and
 *    that emptiness is what User::isCompanyWide() reads — never substitute a
 *    branch here;
 *  - the notification is sent past the commit and cannot fail the hire. A dead
 *    SMS gateway used to surface as "Failed to create employee" on an employee
 *    that had already been created, so the admin tried again and hit the
 *    duplicate-phone rule.
 */
class EmployeeProvisioningService
{
    /**
     * Create (or adopt) the user, attach the role and branches, open the
     * employee record.
     *
     * @param  array<int, int>  $branchIds  Already normalised for the role: exactly
     *                                      one, one or more, or empty for a
     *                                      company-wide role.
     * @param  array<string, mixed>  $details  HR and emergency-contact fields.
     * @param  Closure(User, ?string): mixed|null  $notification  Receives the user and
     *                                                            the password in the clear — null only
     *                                                            when the holder chose it and we hold
     *                                                            just the hash. Return null to send
     *                                                            nothing.
     */
    public function provision(
        string $name,
        string $phone,
        ?string $email,
        Role|string $role,
        array $branchIds,
        PasswordPlan $password,
        array $details = [],
        mixed $hireDate = null,
        EmployeeStatus|string|null $status = null,
        ?Closure $notification = null,
    ): ProvisionedEmployee {
        $roleValue = $role instanceof Role ? $role->value : $role;

        $employee = DB::transaction(function () use (
            $name, $phone, $email, $roleValue, $branchIds, $password, $details, $hireDate, $status
        ) {
            $user = $this->resolveUser($name, $phone, $email, $password);

            // One role per user. syncRoles, not assignRole — reusing an existing
            // user (a former cashier rehired as a rider, or a customer being
            // brought on staff) used to leave both roles attached, and every
            // screen that reads roles->first() then reported whichever came back
            // first while the permissions were the union of the two.
            $user->syncRoles([$roleValue]);
            $user->syncPermissions([]);

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => $this->nextEmployeeNumber(),
                'hire_date' => $hireDate ?? now(),
                'status' => $status instanceof EmployeeStatus
                    ? $status->value
                    : ($status ?? EmployeeStatus::Active->value),
                ...$this->allowedDetails($details),
            ]);

            $employee->branches()->sync($branchIds);

            return $employee;
        });

        // Past the commit, the hire has happened. Telling them about it is a
        // separate concern and must not be able to undo it.
        if ($notification) {
            $message = $notification($employee->user, $password->plain);

            if ($message) {
                $this->notifyQuietly($employee->user, $message);
            }
        }

        return new ProvisionedEmployee(
            $employee->load(['user.roles.permissions', 'user.permissions', 'branches']),
            $password->disclosable,
        );
    }

    /**
     * Reuse the account behind this phone if there is one, otherwise open a new
     * one.
     *
     * The name is written on reuse deliberately: whoever is doing the hiring has
     * the person in front of them, and a staff roster reading "Kwame" because
     * that is what they once typed at checkout helps nobody. This is not the
     * customer-registration path that the identity-separation work stopped from
     * overwriting names — see docs and the identity work before changing it.
     */
    private function resolveUser(string $name, string $phone, ?string $email, PasswordPlan $password): User
    {
        $existing = User::where('phone', $phone)->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'password' => $password->hash,
                'recoverable_password' => $password->recoverable,
                'must_reset_password' => $password->mustReset,
            ]);

            // Only fill a gap. Overwriting the email they already sign in with
            // would lock them out of their own account.
            if (filled($email) && ! $existing->email) {
                $existing->update(['email' => $email]);
            }

            return $existing;
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password->hash,
            'recoverable_password' => $password->recoverable,
            'must_reset_password' => $password->mustReset,
        ]);
    }

    /**
     * Next EMPxxxxx, derived under a row lock inside the caller's transaction so
     * two admins hiring at the same moment cannot land on the same number.
     */
    private function nextEmployeeNumber(): string
    {
        $max = Employee::lockForUpdate()
            ->where('employee_no', 'like', 'EMP%')
            ->pluck('employee_no')
            ->map(fn (string $no) => (int) substr($no, 3))
            ->max() ?? 0;

        return 'EMP'.str_pad((int) $max + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Whitelist, not the caller's array. The recruitment form is filled in by a
     * member of the public and its payload must not be able to reach a column
     * nobody meant to expose.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function allowedDetails(array $details): array
    {
        $allowed = [
            'ssnit_number', 'ghana_card_id', 'tin_number',
            'date_of_birth', 'nationality', 'emergency_contact_name',
            'emergency_contact_phone', 'emergency_contact_relationship',
        ];

        return array_filter(
            array_intersect_key($details, array_flip($allowed)),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * Send without letting a delivery failure fail the request. Everything these
     * announce has already been committed, so a dead SMS gateway is news for the
     * log, not a reason to report the work undone.
     */
    private function notifyQuietly(User $user, mixed $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Staff notification failed to send', [
                'user_id' => $user->id,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
