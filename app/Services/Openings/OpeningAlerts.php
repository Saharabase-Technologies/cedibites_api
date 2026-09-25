<?php

namespace App\Services\Openings;

use App\Models\BranchOpening;
use App\Models\BranchOpeningAnswer;
use App\Models\User;
use App\Services\Alerts\AdminAlerter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What head office is texted about an opening, and how it is worded.
 *
 * The rule the tech admin set for alerts holds here too: one text when
 * something happens, a reminder only while it is still true, and a closing
 * text when it is sorted, so the last word on a problem is never silence.
 */
class OpeningAlerts
{
    public function __construct(
        private readonly AdminAlerter $alerter,
    ) {}

    public function openedWithProblems(BranchOpening $opening): void
    {
        $problems = $opening->problems();
        $count = $problems->count();

        $this->send($opening, 'opening_problems_reported', sprintf(
            '%s opened at %s with %d %s: %s. %s has until %s to fix %s.',
            $opening->branch->name,
            $this->clock($opening->completed_at),
            $count,
            Str::plural('problem', $count),
            $this->names($problems),
            $this->firstName($opening->completer),
            $this->clock($opening->grace_ends_at),
            $count === 1 ? 'it' : 'them',
        ), ['problems' => $problems->pluck('short')->all()]);
    }

    public function allFixed(BranchOpening $opening): void
    {
        $problems = $opening->problems();

        $body = $problems->count() === 1
            ? sprintf('%s: the %s problem is fixed (%s).', $opening->branch->name, $problems->first()->short, $this->clock($opening->problems_resolved_at))
            : sprintf("%s: all %d opening problems are fixed, the last at %s.", $opening->branch->name, $problems->count(), $this->clock($opening->problems_resolved_at));

        $this->send($opening, 'opening_problems_fixed', $body);
    }

    public function openedLate(BranchOpening $opening, OpeningSchedule $schedule): void
    {
        $late = (int) $schedule->opensAt->diffInMinutes($opening->opened_at, absolute: true);

        $this->send($opening, 'branch_opened_late', sprintf(
            '%s opened at %s, %d %s late.',
            $opening->branch->name,
            $this->clock($opening->opened_at),
            $late,
            Str::plural('minute', $late),
        ), ['minutes_late' => $late]);
    }

    public function graceExpired(BranchOpening $opening): void
    {
        $outstanding = $opening->outstandingProblems();
        $count = $outstanding->count();
        $grace = (int) config('openings.grace_minutes', 60);

        $this->send($opening, 'opening_grace_expired', sprintf(
            '%s still has %d %s %s after opening: %s.',
            $opening->branch->name,
            $count,
            Str::plural('problem', $count),
            $grace === 60 ? 'an hour' : "{$grace} minutes",
            $this->names($outstanding),
        ), ['problems' => $outstanding->pluck('short')->all()]);
    }

    public function reminder(BranchOpening $opening): void
    {
        $outstanding = $opening->outstandingProblems();
        $count = $outstanding->count();

        $this->send($opening, 'opening_problems_reminder', sprintf(
            'Reminder. %s still has %d %s from this morning: %s.',
            $opening->branch->name,
            $count,
            Str::plural('problem', $count),
            $this->names($outstanding),
        ), ['problems' => $outstanding->pluck('short')->all()]);
    }

    public function notOpened(BranchOpening $opening, OpeningSchedule $schedule, bool $again): void
    {
        $branch = $opening->branch->name;

        if ($again) {
            $body = sprintf('%s is still not open at %s. It was due at %s.', $branch, $this->clock(now()), $this->clock($schedule->opensAt));
        } elseif ($opening->isStarted()) {
            $needed = $opening->answers->filter->isRequired();
            $answered = $needed->filter->isAnswered()->count();
            $body = sprintf(
                '%s has not opened. It was due at %s. %s started the checklist at %s and has answered %d of %d.',
                $branch,
                $this->clock($schedule->opensAt),
                $this->firstName($opening->starter),
                $this->clock($opening->started_at),
                $answered,
                $needed->count(),
            );
        } else {
            $body = sprintf('%s has not opened. It was due at %s. Nobody has started the checklist.', $branch, $this->clock($schedule->opensAt));
        }

        $this->send($opening, $again ? 'branch_still_not_open' : 'branch_not_open', $body);
    }

    public function openedByHeadOffice(BranchOpening $opening, User $by, string $reason): void
    {
        $this->send($opening, 'branch_opened_without_checklist', sprintf(
            '%s opened %s without the checklist. Reason: %s',
            $by->name,
            $opening->branch->name,
            Str::limit(rtrim(trim($reason), '.'), 90).'.',
        ), ['reason' => $reason, 'by' => $by->id]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function send(BranchOpening $opening, string $event, string $body, array $context = []): void
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/admin/openings/'.$opening->id;

        $this->alerter->send($event, "CediBites: {$body} {$url}", $context + [
            'branch_id' => $opening->branch_id,
            'branch_opening_id' => $opening->id,
            'business_date' => $opening->business_date?->toDateString(),
        ]);
    }

    /**
     * "gas, washrooms and 2 more" rather than the full sentences: a text is
     * read on a phone, and the page has the detail.
     *
     * @param  Collection<int, BranchOpeningAnswer>  $answers
     */
    private function names(Collection $answers): string
    {
        $shorts = $answers->pluck('short')->values();

        if ($shorts->count() <= 3) {
            return $shorts->count() > 1
                ? $shorts->slice(0, -1)->implode(', ').' and '.$shorts->last()
                : (string) $shorts->first();
        }

        return $shorts->take(3)->implode(', ').' and '.($shorts->count() - 3).' more';
    }

    private function firstName(?User $user): string
    {
        return $user ? Str::before(trim($user->name), ' ') : 'The manager';
    }

    /** Ghana is UTC+0 all year and `app.timezone` is UTC, so this is the time on the wall. */
    private function clock(?Carbon $at): string
    {
        return $at ? $at->format('g:i a') : 'an unknown time';
    }
}
