<?php

namespace App\Services\Shifts;

use App\Enums\Role;
use App\Events\StaffSessionEvent;
use App\Models\Shift;
use App\Models\User;
use App\Services\Payments\ShiftMoney;
use Illuminate\Support\Facades\DB;

/**
 * How a shift ends, and the two sign-offs on it (staff portal D29, D30).
 *
 * A shift ends when its person signs out, or when they clock out. Clock out is
 * a confirmation, not a count: the person sees the money they took by method
 * and confirms it, and what they saw is stored as it was at the button. Then
 * the branch manager confirms the shift. Both are kept on the shift row, so
 * the figure a cashier confirms is the figure the manager and the admin read.
 */
class ShiftClockOut
{
    public function __construct(private ShiftMoney $money) {}

    /** End a shift that is still open, and keep the money with the record of it. */
    public function end(Shift $shift, User $by, string $how): void
    {
        if ($shift->logout_at !== null) {
            return;
        }

        $shift->forceFill(['logout_at' => now()])->save();
        $this->log($shift, $by, 'shift_ended', "Shift ended ({$how})", ['how' => $how]);
    }

    /**
     * Confirm the money, end the shift and sign the person out, in one step.
     *
     * `$seenTotal` is the total the sheet showed. When the money has moved
     * since, nothing is stored, so nobody confirms a figure they did not see.
     *
     * @throws ShiftActionRefused
     */
    public function clockOut(Shift $shift, User $by, ?string $note, ?float $seenTotal): Shift
    {
        $shift = DB::transaction(function () use ($shift, $by, $note, $seenTotal) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($locked->employee_id !== $by->employee?->id) {
                throw new ShiftActionRefused('Only the person on the shift can clock it out.', 403);
            }

            if ($locked->logout_at !== null) {
                throw new ShiftActionRefused('This shift has already ended.', 409);
            }

            $view = $this->money->clockOutView($locked);

            if ($seenTotal !== null && abs($view['total'] - $seenTotal) > 0.004) {
                throw new ShiftActionRefused('The money changed since you opened Clock out. Look at it again.', 409, $view);
            }

            $now = now();
            $owing = $view['owing'];
            unset($view['owing']);

            $locked->forceFill([
                'logout_at' => $now,
                'clocked_out_at' => $now,
                'clock_out_money' => $view,
                'clock_out_owing' => $owing,
                'clock_out_note' => $this->clean($note),
            ])->save();

            $this->log($locked, $by, 'shift_clocked_out', 'Clocked out', [
                'money' => $view['money'],
                'total' => $view['total'],
                'note' => $locked->clock_out_note,
            ]);

            // Clocking out signs out, everywhere, as Sign out does.
            $by->tokens()->delete();

            return $locked;
        });

        // Tells the person's other tabs. The Clock out is stored and the
        // tokens are gone by now, so Reverb being down must not fail it.
        rescue(fn () => StaffSessionEvent::dispatch($by, 'session.revoked'));

        return $shift;
    }

    /**
     * The branch manager confirms a shift after it ended. Never their own, and
     * never another branch's. At a branch with no manager, an admin confirms
     * (Q2). A manager's own shift has nobody else to confirm it (Q3).
     *
     * @throws ShiftActionRefused
     */
    public function confirm(Shift $shift, User $by, ?string $note): Shift
    {
        return DB::transaction(function () use ($shift, $by, $note) {
            $locked = Shift::with(['employee.user', 'branch'])->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $employee = $by->employee;
            $name = $locked->employee?->user?->name ?? 'This person';
            $first = strtok($name, ' ');

            if (! $employee) {
                throw new ShiftActionRefused('Only a manager or an admin can confirm a shift.', 403);
            }

            if ($locked->employee_id === $employee->id) {
                throw new ShiftActionRefused('You cannot confirm your own shift.', 403);
            }

            $branchHasManager = $locked->branch?->managers()->exists() ?? false;
            $managesIt = $by->hasRole(Role::Manager)
                && $locked->branch_id !== null
                && $employee->managedBranches()->where('branches.id', $locked->branch_id)->exists();
            $adminWithNoManager = $by->hasAnyRole([Role::Admin, Role::TechAdmin]) && ! $branchHasManager;

            if (! $managesIt && ! $adminWithNoManager) {
                throw new ShiftActionRefused(
                    $by->hasAnyRole([Role::Admin, Role::TechAdmin])
                        ? "{$locked->branch?->name} has a manager. They confirm its shifts."
                        : 'Only the manager of the branch can confirm this shift.',
                    403,
                );
            }

            if ($locked->employee?->user?->hasRole(Role::Manager)) {
                throw new ShiftActionRefused("A manager's own shift needs no second confirmation.", 422);
            }

            if ($locked->employee?->user?->hasAnyRole([Role::Admin, Role::TechAdmin])) {
                throw new ShiftActionRefused("An admin's shift needs no confirmation.", 422);
            }

            if ($locked->logout_at === null) {
                throw new ShiftActionRefused("{$first} is still on shift.", 422);
            }

            if ($locked->manager_confirmed_at !== null) {
                throw new ShiftActionRefused('This shift is already confirmed.', 409);
            }

            $locked->forceFill([
                'manager_confirmed_by' => $employee->id,
                'manager_confirmed_at' => now(),
                'manager_note' => $this->clean($note),
            ])->save();

            $this->log($locked, $by, 'shift_confirmed', "Confirmed {$name}'s shift", ['note' => $locked->manager_note]);

            return $locked;
        });
    }

    private function clean(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Shift $shift, User $by, string $event, string $what, array $properties): void
    {
        $shift->loadMissing(['employee.user', 'branch']);
        $who = $shift->employee?->user?->name ?? 'someone';

        activity('shifts')
            ->causedBy($by)
            ->performedOn($shift)
            ->event($event)
            ->withProperties(['branch' => $shift->branch?->name, 'money' => $this->money->for($shift)] + $properties)
            ->log("{$what}: {$who} at ".($shift->branch?->name ?? 'the call centre'));
    }
}
