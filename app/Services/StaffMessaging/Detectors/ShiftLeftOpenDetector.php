<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Models\Shift;
use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A shift still open long after anybody could plausibly still be working it.
 *
 * Costs real money in reporting: an open shift keeps accumulating orders against
 * whoever forgot to close it, so one person's Friday can absorb Saturday's sales
 * and both days' figures are then wrong. It also hides the takings, since the
 * shift total is what gets counted against the cash drawer.
 *
 * The message goes to the person themselves first. This is the most
 * forgetfulness-shaped of all the rules — almost never anything worse — and it is
 * fixed in one tap by the person who left it.
 */
class ShiftLeftOpenDetector implements DetectsStaffEvent
{
    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection
    {
        $hours = (int) $rule->condition('hours');
        $cutoff = now()->subHours($hours);

        return Shift::query()
            ->whereNull('logout_at')
            ->where('login_at', '<=', $cutoff)
            ->where('login_at', '>=', $since)
            ->with('employee.user')
            ->get()
            ->map(function (Shift $shift) {
                return new RuleMatch(
                    subject: $shift,
                    actorUserId: $shift->employee?->user_id,
                    branchId: $shift->branch_id,
                    mergeData: [
                        'hours' => (int) $shift->login_at->diffInHours(now()),
                        'shift_started' => $shift->login_at->format('D j M, g:ia'),
                    ],
                );
            })
            ->values();
    }

    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool
    {
        $shift = $match->subject;

        if (! $shift instanceof Shift) {
            return false;
        }

        // Closed in the meantime — which is the outcome the rule wanted, so
        // sending now would be nagging somebody for doing the right thing.
        return $shift->fresh()?->logout_at === null;
    }
}
