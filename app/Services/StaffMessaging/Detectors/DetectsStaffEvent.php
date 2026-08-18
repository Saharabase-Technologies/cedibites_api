<?php

namespace App\Services\StaffMessaging\Detectors;

use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\RuleMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds the things one rule is about.
 *
 * A detector only ever reads. It does not decide whether to send, who to send to,
 * or whether a cooldown applies — those belong to the guard and the evaluator, so
 * that the live run and the dry run can share every line of detection code and
 * differ only in what they do with the result.
 *
 * That sharing is the point. Two copies would drift, and a dry run promising
 * something the live rule does not do is worse than no dry run at all, because
 * somebody approved the send on the strength of it.
 */
interface DetectsStaffEvent
{
    /**
     * @param  CarbonInterface  $since  the oldest record worth examining
     * @return Collection<int, RuleMatch>
     */
    public function detect(StaffMessageRule $rule, CarbonInterface $since): Collection;

    /**
     * Whether the match still holds right now.
     *
     * Re-checked at send time, minutes after detection. An order stalled when the
     * scheduler looked may well have moved by the time the message goes out, and
     * cautioning somebody about work they have since done is the fastest way to
     * make the whole channel ignorable.
     */
    public function stillHolds(StaffMessageRule $rule, RuleMatch $match): bool;
}
