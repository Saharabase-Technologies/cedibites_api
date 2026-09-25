<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\Openings\BranchOpeningService;
use App\Services\Openings\OpeningAlerts;
use Illuminate\Console\Command;

/**
 * Tells head office when a branch has not opened, and when problems admitted at
 * opening are still not fixed.
 *
 *   Not open 15 minutes after opening time: one text, and one more an hour
 *   after that if it is still shut. Then it is head office's call.
 *   Problems still open when the grace period ends: one text, then a reminder
 *   every three hours while any remain, until the branch closes for the day.
 *
 * Each of these is stamped on the day's opening row, so a text goes once
 * however often this runs.
 */
class WatchOpenings extends Command
{
    protected $signature = 'openings:watch';

    protected $description = 'Text head office about branches not opened on time, and opening problems not fixed';

    public function handle(BranchOpeningService $openings, OpeningAlerts $alerts): int
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->where('requires_opening_checklist', true)
            ->with('operatingHours')
            ->get();

        foreach ($branches as $branch) {
            if (! $openings->required($branch)) {
                continue;
            }

            try {
                $this->watch($branch, $openings, $alerts);
            } catch (\Throwable $e) {
                // One branch's bad data must not silence the others.
                report($e);
                $this->error("{$branch->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function watch(Branch $branch, BranchOpeningService $openings, OpeningAlerts $alerts): void
    {
        $schedule = $openings->schedule($branch);

        if (! $schedule->tradingDay || $schedule->opensAt === null) {
            return;
        }

        $opening = $openings->current($branch);
        $now = now();

        if (! $opening?->isOpen()) {
            if ($now->lt($schedule->lateAt()) || ! $schedule->isBeforeClosing()) {
                return;
            }

            $opening ??= $openings->row($branch, $schedule->businessDate);
            $opening->loadMissing(['answers', 'starter', 'branch']);

            if ($opening->late_alerted_at === null) {
                $alerts->notOpened($opening, $schedule, again: false);
                $opening->update(['late_alerted_at' => $now]);
                $this->line("{$branch->name}: not open, head office told");
            } elseif (
                $opening->late_reminded_at === null
                && $now->gte($opening->late_alerted_at->copy()->addMinutes(max(1, (int) config('openings.late_reminder_minutes', 60))))
            ) {
                $alerts->notOpened($opening, $schedule, again: true);
                $opening->update(['late_reminded_at' => $now]);
                $this->line("{$branch->name}: still not open, head office reminded");
            }

            return;
        }

        $opening->loadMissing(['answers', 'branch']);

        if ($opening->grace_ends_at === null || $opening->outstandingProblems()->isEmpty() || $now->lt($opening->grace_ends_at)) {
            return;
        }

        if ($opening->grace_alerted_at === null) {
            $alerts->graceExpired($opening);
            $opening->update(['grace_alerted_at' => $now]);
            $this->line("{$branch->name}: problems past the grace period, head office told");

            return;
        }

        $last = $opening->last_reminded_at ?? $opening->grace_alerted_at;
        $every = max(1, (int) config('openings.reminder_hours', 3));

        if ($schedule->isBeforeClosing() && $now->gte($last->copy()->addHours($every))) {
            $alerts->reminder($opening);
            $opening->update(['last_reminded_at' => $now]);
            $this->line("{$branch->name}: problems still open, head office reminded");
        }
    }
}
