<?php

namespace App\Services\Openings;

use App\Models\Branch;
use App\Models\BranchOperatingHour;
use Illuminate\Support\Carbon;

/**
 * When one branch opens on one business day, and the times that hang off it.
 *
 * Ghana is UTC+0 all year and `app.timezone` is UTC, so these are the times on
 * the wall at the branch.
 */
final class OpeningSchedule
{
    private function __construct(
        public readonly string $businessDate,
        public readonly bool $tradingDay,
        public readonly ?Carbon $opensAt,
        public readonly ?Carbon $closesAt,
    ) {}

    public static function for(Branch $branch, string $businessDate): self
    {
        $day = strtolower(Carbon::parse($businessDate)->format('l'));

        /** @var BranchOperatingHour|null $row */
        $row = $branch->relationLoaded('operatingHours')
            ? $branch->operatingHours->firstWhere('day_of_week', $day)
            : $branch->operatingHours()->where('day_of_week', $day)->first();

        if (! $branch->is_active || $row === null) {
            return new self($businessDate, false, null, null);
        }

        // Shut by hand for today is not a trading day; opened by hand on a
        // day the timetable has closed is one.
        $trading = $row->overrideInForce() ? (bool) $row->manual_override_open : (bool) $row->is_open;

        $opensAt = $row->open_time ? Carbon::parse($businessDate.' '.$row->open_time) : null;
        $closesAt = $row->close_time ? Carbon::parse($businessDate.' '.$row->close_time) : null;

        if ($opensAt && $closesAt && $closesAt->lte($opensAt)) {
            $closesAt->addDay();
        }

        return new self($businessDate, $trading, $opensAt, $closesAt);
    }

    /** From when the manager may start the checklist, and staff may sign in to prepare. */
    public function checklistFrom(): ?Carbon
    {
        return $this->opensAt?->copy()->subMinutes(max(0, (int) config('openings.early_start_minutes', 120)));
    }

    /** Not open by this time is late. */
    public function lateAt(): ?Carbon
    {
        return $this->opensAt?->copy()->addMinutes(max(0, (int) config('openings.late_after_minutes', 15)));
    }

    /** Online orders taken while the manager finishes the checklist, until this time. */
    public function onlineWaitUntil(): ?Carbon
    {
        return $this->opensAt?->copy()->addMinutes(max(0, (int) config('openings.online_wait_minutes', 30)));
    }

    public function isBeforeChecklistWindow(?Carbon $at = null): bool
    {
        $from = $this->checklistFrom();

        return $from !== null && ($at ?? now())->lt($from);
    }

    public function isInPreOpenWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->tradingDay
            && $this->opensAt !== null
            && $at->gte($this->checklistFrom())
            && $at->lt($this->opensAt);
    }

    public function isBeforeClosing(?Carbon $at = null): bool
    {
        return $this->closesAt === null || ($at ?? now())->lt($this->closesAt);
    }
}
