<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Undo the damage done by the old "keep the display name current" behaviour in
 * OrderCreationService / PosOrderController, which rewrote `users.name` from
 * whatever a cashier typed at the counter. When the phone belonged to a staff
 * member, that renamed their staff identity.
 *
 * The original names survive because User::getActivitylogOptions() logs `name`
 * with logOnlyDirty — every overwrite left its previous value in
 * activity_log.properties->old->name. The earliest such value is the name the
 * account was created with.
 *
 * A legitimate self-service profile edit is told apart from an order overwrite
 * by the causer: updateProfile runs as the user themselves (causer == subject),
 * whereas an order overwrite ran as the cashier, or as nobody at all for an
 * online guest order. Admin-initiated renames also show causer != subject, so
 * this is a strong hint rather than a proof — which is why nothing is written
 * without --apply, and why the dry run prints the causer for review.
 */
class RestoreOverwrittenUserNames extends Command
{
    protected $signature = 'users:restore-overwritten-names
                            {--apply : Write the restored names (default is a dry run)}
                            {--user= : Restore a single user by id}
                            {--include-customers : Also consider users with no employee record}';

    protected $description = 'Restore user names clobbered by the old order-driven name overwrite';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyUser = $this->option('user');
        $includeCustomers = (bool) $this->option('include-customers');

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be written. Re-run with --apply to commit.');
        }

        $candidates = $this->collectCandidates($onlyUser, $includeCustomers);

        if ($candidates === []) {
            $this->info('No overwritten names found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['User', 'Kind', 'Current name', 'Restore to', 'Overwritten at', 'By'],
            array_map(fn (array $c): array => [
                $c['user']->id,
                $c['is_staff'] ? 'STAFF' : 'customer',
                $c['user']->name,
                $c['original'],
                $c['changed_at'],
                $c['causer'],
            ], $candidates)
        );

        if (! $apply) {
            $this->newLine();
            $this->info(count($candidates).' name(s) would be restored.');
            $this->line('Review the "By" column: a row caused by the user themselves is a genuine');
            $this->line('profile edit and should be left alone — use --user=<id> to restore selectively.');

            return self::SUCCESS;
        }

        $restored = 0;

        foreach ($candidates as $candidate) {
            $user = $candidate['user'];
            $from = $user->name;

            // Written without touching updated_at so the restore does not read as
            // fresh account activity, and logged deliberately for the audit trail.
            $user->name = $candidate['original'];
            $user->saveQuietly();

            activity('auth')
                ->performedOn($user)
                ->event('name_restored')
                ->withProperties(['from' => $from, 'to' => $candidate['original']])
                ->log("Restored overwritten name: {$from} -> {$candidate['original']}");

            $restored++;
        }

        $this->info("Restored {$restored} name(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{user: User, original: string, changed_at: string, causer: string, is_staff: bool}>
     */
    private function collectCandidates(?string $onlyUser, bool $includeCustomers): array
    {
        $users = User::query()
            ->with('employee')
            ->when($onlyUser, fn ($q) => $q->whereKey($onlyUser))
            ->get()
            ->keyBy('id');

        if ($users->isEmpty()) {
            return [];
        }

        // Earliest-first, so the first name change seen for a user carries the
        // value the account originally held.
        $earliest = [];

        Activity::query()
            ->where('subject_type', User::class)
            ->whereIn('subject_id', $users->keys())
            ->where('event', 'updated')
            ->orderBy('id')
            ->chunk(500, function ($activities) use (&$earliest): void {
                foreach ($activities as $activity) {
                    $subjectId = (int) $activity->subject_id;

                    if (isset($earliest[$subjectId])) {
                        continue;
                    }

                    $old = data_get($activity->properties, 'old.name');
                    $new = data_get($activity->properties, 'attributes.name');

                    if (! is_string($old) || $old === '' || $old === $new) {
                        continue;
                    }

                    $earliest[$subjectId] = [
                        'original' => $old,
                        'changed_at' => (string) $activity->created_at,
                        'causer_id' => $activity->causer_id ? (int) $activity->causer_id : null,
                    ];
                }
            });

        $candidates = [];

        foreach ($earliest as $userId => $change) {
            $user = $users->get($userId);

            if (! $user) {
                continue;
            }

            // Already correct — the name was changed and changed back, or restored
            // by an earlier run.
            if ($user->name === $change['original']) {
                continue;
            }

            $isStaff = $user->employee !== null;

            if (! $isStaff && ! $includeCustomers) {
                continue;
            }

            $causerId = $change['causer_id'];
            $causer = match (true) {
                $causerId === null => 'guest / system',
                $causerId === $userId => 'self (likely a genuine edit)',
                default => 'user #'.$causerId,
            };

            $candidates[] = [
                'user' => $user,
                'original' => $change['original'],
                'changed_at' => $change['changed_at'],
                'causer' => $causer,
                'is_staff' => $isStaff,
            ];
        }

        // Staff first — that is the damage the operator actually feels.
        usort($candidates, fn (array $a, array $b): int => [$b['is_staff'], $a['user']->id] <=> [$a['is_staff'], $b['user']->id]);

        return $candidates;
    }
}
