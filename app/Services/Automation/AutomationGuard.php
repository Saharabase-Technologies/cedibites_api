<?php

namespace App\Services\Automation;

use App\Models\AutomationFire;
use App\Models\AutomationRule;

/**
 * The reasons not to send.
 *
 * This is the most important class in the automation feature. Automated
 * messaging goes wrong on volume rather than on logic — every rule looks
 * reasonable alone, and the damage is done by several reasonable rules agreeing
 * about the same person on the same afternoon.
 *
 * Every method returns a reason string or null. Null means "no objection",
 * which reads oddly until you remember that the caller's question is "why
 * shouldn't I?" rather than "may I?".
 */
class AutomationGuard
{
    /**
     * Why this rule should not message this number right now.
     *
     * Checked in cost order — the free checks first, the query last — because
     * this runs on every completed order against every live rule.
     */
    public function objection(AutomationRule $rule, string $phone, ?int $excludeFireId = null): ?string
    {
        if (! config('automation.enabled', false)) {
            return AutomationFire::FEATURE_OFF;
        }

        if (! $rule->is_active) {
            return AutomationFire::FEATURE_OFF;
        }

        if (! $this->isSampled($rule, $phone)) {
            return AutomationFire::NOT_SAMPLED;
        }

        if ($this->overLifetimeCap($rule, $phone, $excludeFireId)) {
            return AutomationFire::LIFETIME_CAP;
        }

        if ($this->withinCooldown($rule, $phone, $excludeFireId)) {
            return AutomationFire::COOLDOWN;
        }

        return null;
    }

    /**
     * Has this number heard from ANY rule inside the cooldown?
     *
     * Across every rule, not just this one. Per-rule cooldowns are the trap that
     * makes this feature annoying: three rules each politely waiting three days
     * still produce three texts in one afternoon, and each of them is behaving
     * correctly by its own reckoning.
     *
     * Only counts firings that actually sent. A suppressed row is a record that
     * we decided not to message somebody, and it would be perverse for that to
     * be the reason we cannot message them.
     */
    public function withinCooldown(AutomationRule $rule, string $phone, ?int $excludeFireId = null): bool
    {
        return AutomationFire::where('phone', $phone)
            ->notSuppressed()
            ->when($excludeFireId, fn ($q) => $q->where('id', '!=', $excludeFireId))
            ->where('fired_at', '>=', now()->subDays($rule->effectiveCooldownDays()))
            ->exists();
    }

    public function overLifetimeCap(AutomationRule $rule, string $phone, ?int $excludeFireId = null): bool
    {
        if ($rule->max_per_customer === null) {
            return false;
        }

        return AutomationFire::where('automation_rule_id', $rule->id)
            ->where('phone', $phone)
            ->notSuppressed()
            ->when($excludeFireId, fn ($q) => $q->where('id', '!=', $excludeFireId))
            ->count() >= $rule->max_per_customer;
    }

    /**
     * Whether this person falls inside the rule's sample.
     *
     * STABLE PER PERSON, not random per evaluation. A fresh dice roll each time
     * would mean the same customer is picked and skipped at random, so nobody is
     * reliably excluded, the cooldown stops protecting anyone in particular, and
     * two runs of the dry run would disagree about who gets messaged.
     *
     * Hashing the rule id with the phone means each rule samples a different
     * fifth of the base, rather than every rule talking to the same unlucky
     * twenty per cent.
     */
    public function isSampled(AutomationRule $rule, string $phone): bool
    {
        $rate = max(0, min(100, (int) $rule->sample_rate));

        if ($rate >= 100) {
            return true;
        }

        if ($rate === 0) {
            return false;
        }

        $bucket = hexdec(substr(md5($rule->id.':'.$phone), 0, 8)) % 100;

        return $bucket < $rate;
    }
}
