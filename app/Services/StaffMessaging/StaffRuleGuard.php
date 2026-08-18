<?php

namespace App\Services\StaffMessaging;

use App\Enums\StaffMessageSuppression;
use App\Models\StaffMessageRule;
use App\Models\StaffMessageRuleFire;

/**
 * The restraints. Everything here exists because the failure mode of an
 * automated messaging system is not silence — it is volume, and volume is what
 * teaches people to ignore the channel.
 */
class StaffRuleGuard
{
    /**
     * Why this person should not be messaged about this thing, or null to go
     * ahead.
     *
     * Order matters: the cheapest and most decisive checks first, so a run over
     * hundreds of matches does not do a per-recipient count query for fires the
     * cooldown was going to reject anyway.
     */
    public function suppressionFor(StaffMessageRule $rule, int $userId, string $cooldownKey): ?StaffMessageSuppression
    {
        if (! config('staff_messaging.automation_enabled')) {
            return StaffMessageSuppression::FeatureOff;
        }

        if (! $rule->is_active) {
            return StaffMessageSuppression::RuleInactive;
        }

        if ($this->withinCooldown($rule, $userId, $cooldownKey)) {
            return StaffMessageSuppression::Cooldown;
        }

        if ($this->recipientCapped($userId)) {
            return StaffMessageSuppression::RecipientCapped;
        }

        return null;
    }

    /**
     * Already told this person about this exact thing, recently enough.
     *
     * Only SENT fires count. A fire suppressed for cooldown must not itself
     * restart the cooldown, or the window would extend forever every time the
     * scheduler ran and the person would never hear about the thing again.
     */
    private function withinCooldown(StaffMessageRule $rule, int $userId, string $cooldownKey): bool
    {
        [$subjectType, $subjectId] = $this->splitKey($cooldownKey);

        return StaffMessageRuleFire::query()
            ->where('rule_id', $rule->id)
            ->where('user_id', $userId)
            ->when($subjectType !== null, fn ($q) => $q->where('subject_type', $subjectType))
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId))
            ->when($subjectType === null, fn ($q) => $q->whereNull('subject_type'))
            ->whereNull('suppressed_reason')
            ->where('fired_at', '>=', now()->subMinutes(max(1, $rule->cooldown_minutes)))
            ->exists();
    }

    /**
     * This person has had their fill of automated messages this hour.
     *
     * Counts only rule-driven sends. A message an admin typed by hand is never
     * held back by this — a human deciding to write to somebody has already made
     * the judgement this cap exists to make on the machine's behalf.
     */
    private function recipientCapped(int $userId): bool
    {
        $cap = (int) config('staff_messaging.recipient_hourly_cap', 3);

        if ($cap <= 0) {
            return false;
        }

        return StaffMessageRuleFire::query()
            ->where('user_id', $userId)
            ->whereNull('suppressed_reason')
            ->where('fired_at', '>=', now()->subHour())
            ->count() >= $cap;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function splitKey(string $cooldownKey): array
    {
        if (str_starts_with($cooldownKey, 'actor:')) {
            return [null, null];
        }

        $position = strrpos($cooldownKey, ':');

        if ($position === false) {
            return [null, null];
        }

        return [substr($cooldownKey, 0, $position), (int) substr($cooldownKey, $position + 1)];
    }
}
