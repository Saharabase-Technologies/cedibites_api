<?php

namespace App\Services\StaffMessaging;

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageSuppression;
use App\Enums\StaffMessageTarget;
use App\Models\StaffMessage;
use App\Models\StaffMessageRule;
use App\Models\StaffMessageRuleFire;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Runs the rules: detect, decide, record, send.
 *
 * Runs whether or not the feature is switched on. With the kill switch off it
 * detects and records exactly as it would otherwise and marks every fire
 * `feature_off` — which is how a rule accumulates a track record somebody can
 * look at before letting it near a real person. A rule switched on cold is a rule
 * nobody can vouch for.
 */
class StaffRuleEvaluator
{
    public function __construct(
        private DetectorRegistry $detectors,
        private StaffRuleGuard $guard,
        private StaffAudienceResolver $audience,
        private StaffMessageRenderer $renderer,
        private StaffMessageDispatcher $dispatcher,
    ) {}

    /**
     * @return array<string, int>  matched / sent / held_back
     */
    public function run(?StaffMessageRule $only = null): array
    {
        $rules = $only
            ? collect([$only])
            // Highest priority first, so that when several rules match the same
            // order the important one claims it. Processing in id order would
            // make which rule wins depend on the order they happened to be
            // created in.
            : StaffMessageRule::query()->orderByDesc('priority')->orderBy('id')->get();

        $since = now()->subHours((int) config('staff_messaging.lookback_hours', 24));

        $totals = ['matched' => 0, 'sent' => 0, 'held_back' => 0];

        // (subject, user) pairs already claimed this run. One order that is both
        // stalled AND carries a junk phone number is two matches; the person
        // should hear about it once.
        $claimed = [];

        foreach ($rules as $rule) {
            try {
                $result = $this->runRule($rule, $since, $claimed);
            } catch (\Throwable $e) {
                // One malformed rule must not abort the run for the others.
                Log::error('Staff message rule failed to evaluate', [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, bool>  $claimed
     * @return array<string, int>
     */
    private function runRule(StaffMessageRule $rule, \Carbon\CarbonInterface $since, array &$claimed): array
    {
        $detector = $this->detectors->for($rule->event);
        $matches = $detector->detect($rule, $since);

        $counts = ['matched' => 0, 'sent' => 0, 'held_back' => 0];

        foreach ($matches as $match) {
            $recipients = $this->recipientsFor($rule, $match);

            if ($recipients->isEmpty()) {
                $this->record($rule, $match, null, StaffMessageSuppression::NoRecipients);
                $counts['matched']++;
                $counts['held_back']++;

                continue;
            }

            foreach ($recipients as $user) {
                $counts['matched']++;
                $claimKey = $match->cooldownKey().'|'.$user->id;

                if (isset($claimed[$claimKey])) {
                    $this->record($rule, $match, $user->id, StaffMessageSuppression::LowerPriority);
                    $counts['held_back']++;

                    continue;
                }

                $suppression = $this->guard->suppressionFor($rule, $user->id, $match->cooldownKey());

                if ($suppression !== null) {
                    $this->record($rule, $match, $user->id, $suppression);
                    $counts['held_back']++;

                    continue;
                }

                // Re-check immediately before sending. The gap between detection
                // and here is small, but the same call is what the queued path
                // relies on, and a stalled order really can move inside it.
                if (! $detector->stillHolds($rule, $match)) {
                    $this->record($rule, $match, $user->id, StaffMessageSuppression::AlreadyResolved);
                    $counts['held_back']++;

                    continue;
                }

                $message = $this->compose($rule, $match, $user);
                $this->dispatcher->send($message, collect([$user]));

                $this->record($rule, $match, $user->id, null, $message);
                $claimed[$claimKey] = true;
                $counts['sent']++;
            }
        }

        return $counts;
    }

    /**
     * A message per recipient, not one shared between them.
     *
     * The template is rendered against the individual — their first name, their
     * branch — so two people cannot share a row. It also means one person
     * acknowledging does not mark it acknowledged for everybody, which a shared
     * row would.
     */
    private function compose(StaffMessageRule $rule, RuleMatch $match, User $user): StaffMessage
    {
        return StaffMessage::create([
            'sender_user_id' => null,
            'rule_id' => $rule->id,
            'kind' => $rule->kind->value,
            'subject' => $rule->subject,
            'body' => $this->renderer->render($rule->body_template, $user, $match->branchId, $match->mergeData),
            'audience' => ['rule' => $rule->id, 'user_ids' => [$user->id]],
            'requires_acknowledgement' => $rule->requires_acknowledgement,
            'allow_custom_reply' => $rule->allow_custom_reply,
            'quick_replies' => $rule->quick_replies,
            'sms_fallback_after_minutes' => $rule->sms_fallback_after_minutes,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(StaffMessageRule $rule, RuleMatch $match): Collection
    {
        $targets = $rule->targets();
        $users = collect();

        foreach ($targets as $target) {
            $users = $users->concat(match (StaffMessageTarget::tryFrom($target)) {
                StaffMessageTarget::Actor => $this->actor($match),
                StaffMessageTarget::BranchManagers => $this->branchRole($match->branchId, [RoleEnum::Manager->value]),
                StaffMessageTarget::BranchStaff => $this->branchRole($match->branchId, []),
                StaffMessageTarget::Roles => $this->audience->resolve([
                    'roles' => (array) data_get($rule->target, 'roles', []),
                ]),
                StaffMessageTarget::Admins => $this->audience->itTeam(),
                default => collect(),
            });
        }

        return $users->filter()->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function actor(RuleMatch $match): Collection
    {
        if ($match->actorUserId === null) {
            return collect();
        }

        return User::query()
            ->whereKey($match->actorUserId)
            ->whereHas('employee', fn (Builder $q) => $q->where('status', EmployeeStatus::Active->value))
            ->get();
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function branchRole(?int $branchId, array $roles): Collection
    {
        if ($branchId === null) {
            return collect();
        }

        // `include_company_wide` is false here on purpose. "Managers of that
        // branch" means the people running it — pulling in head office and the
        // call centre, who hold no branch and would otherwise be swept in by the
        // company-wide clause, would turn a local nudge into an all-hands alert.
        return $this->audience->resolve([
            'roles' => $roles,
            'branch_ids' => [$branchId],
            'include_company_wide' => false,
        ]);
    }

    private function record(
        StaffMessageRule $rule,
        RuleMatch $match,
        ?int $userId,
        ?StaffMessageSuppression $suppression,
        ?StaffMessage $message = null,
    ): void {
        if ($suppression !== null && $this->alreadyRecorded($rule, $match, $userId, $suppression)) {
            return;
        }

        StaffMessageRuleFire::create([
            'rule_id' => $rule->id,
            'subject_type' => $match->subjectType(),
            'subject_id' => $match->subjectId(),
            'user_id' => $userId,
            'staff_message_id' => $message?->id,
            'suppressed_reason' => $suppression?->value,
            'fired_at' => now(),
        ]);
    }

    /**
     * Whether this exact observation is already on the record, recently.
     *
     * The scheduler runs every five minutes and every run re-detects the same
     * stalled orders. Writing a row each time would put roughly 288 identical
     * rows per rule per order per day into the table — and it is WORST in the
     * state the feature ships in, with the kill switch off, because then every
     * single evaluation is suppressed and nothing ever drops out of the match
     * set by being sent.
     *
     * A repeat of an identical suppression is not new information, so it is not
     * written. The first one is, which is what preserves the audit trail: "this
     * rule saw this order and held back, for this reason" is answerable, and
     * asking it 288 times a day makes it no more answerable.
     *
     * Sent fires are NEVER deduplicated — they are the cooldown's own bookkeeping
     * and dropping one would let a second message through.
     */
    private function alreadyRecorded(
        StaffMessageRule $rule,
        RuleMatch $match,
        ?int $userId,
        StaffMessageSuppression $suppression,
    ): bool {
        return StaffMessageRuleFire::query()
            ->where('rule_id', $rule->id)
            ->where('suppressed_reason', $suppression->value)
            ->when($userId === null, fn ($q) => $q->whereNull('user_id'))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when(
                $match->subjectType() === null,
                fn ($q) => $q->whereNull('subject_type'),
                fn ($q) => $q->where('subject_type', $match->subjectType())
                    ->where('subject_id', $match->subjectId()),
            )
            ->where('fired_at', '>=', now()->subMinutes(max(1, $rule->cooldown_minutes)))
            ->exists();
    }
}
