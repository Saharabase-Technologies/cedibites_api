<?php

namespace App\Services\StaffMessaging;

use App\Enums\EmployeeStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\StaffMessageTarget;
use App\Models\StaffMessageRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Answers "what would this rule have done?" against real history, writing and
 * sending nothing.
 *
 * Mandatory before any rule is switched on. The number that matters is not the
 * total — it is `busiest_recipient`. A rule reaching three people forty times and
 * a rule reaching three hundred people forty times produce identical totals, and
 * only one of them is somebody's phone buzzing every ten minutes all afternoon.
 *
 * Shares `DetectorRegistry` with the live evaluator rather than reimplementing
 * detection. Two copies would drift, and a dry run that promises something the
 * live rule does not do is worse than no dry run at all — somebody approved the
 * send on the strength of it.
 */
class StaffRuleDryRun
{
    public function __construct(
        private DetectorRegistry $detectors,
        private StaffAudienceResolver $audience,
        private StaffMessageRenderer $renderer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(StaffMessageRule $rule, int $days = 30): array
    {
        $since = now()->subDays($days);
        $matches = $this->detectors->for($rule->event)->detect($rule, $since);

        // Simulated cooldown state: user id + subject key => the moment we last
        // "sent". Walked FORWARD through the window so the cooldown behaves the
        // way it would in life; evaluating newest-first would let every match
        // look like the first one.
        $lastSent = [];
        $perRecipient = [];
        $wouldSend = 0;
        $heldBack = 0;
        $samples = [];

        $ordered = $matches->sortBy(fn (RuleMatch $match) => $match->subject?->created_at ?? now())->values();

        foreach ($ordered as $match) {
            $recipients = $this->recipientsFor($rule, $match);

            if ($recipients->isEmpty()) {
                $heldBack++;

                continue;
            }

            foreach ($recipients as $user) {
                $key = $user->id.'|'.$match->cooldownKey();
                $at = $match->subject?->created_at ?? now();

                if (isset($lastSent[$key]) && $lastSent[$key]->diffInMinutes($at) < max(1, $rule->cooldown_minutes)) {
                    $heldBack++;

                    continue;
                }

                $lastSent[$key] = $at;
                $wouldSend++;
                $perRecipient[$user->id] = ($perRecipient[$user->id] ?? 0) + 1;

                if (count($samples) < 3) {
                    $samples[] = [
                        'to' => $user->name,
                        'body' => $this->renderer->render(
                            $rule->body_template,
                            $user,
                            $match->branchId,
                            $match->mergeData,
                        ),
                    ];
                }
            }
        }

        return [
            'rule' => $rule->name,
            'event' => $rule->event->value,
            'days' => $days,
            'matched' => $matches->count(),
            'would_send' => $wouldSend,
            // The gap is the cooldown doing its job, and saying so stops it being
            // read as a fault.
            'held_back' => $heldBack,
            'people_reached' => count($perRecipient),
            'busiest_recipient' => $perRecipient === [] ? 0 : max($perRecipient),
            'samples' => $samples,
            // The per-recipient hourly cap and the one-rule-wins claim are NOT
            // modelled — both need the other rules, which this does not look at.
            // So `would_send` is a CEILING, which is the safe direction for a
            // number somebody is about to approve.
            'is_ceiling' => true,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(StaffMessageRule $rule, RuleMatch $match): Collection
    {
        $users = collect();

        foreach ($rule->targets() as $target) {
            $users = $users->concat(match (StaffMessageTarget::tryFrom($target)) {
                StaffMessageTarget::Actor => $match->actorUserId
                    ? User::query()
                        ->whereKey($match->actorUserId)
                        ->whereHas('employee', fn (Builder $q) => $q->where('status', EmployeeStatus::Active->value))
                        ->get()
                    : collect(),
                StaffMessageTarget::BranchManagers => $this->branchUsers($match->branchId, [RoleEnum::Manager->value]),
                StaffMessageTarget::BranchStaff => $this->branchUsers($match->branchId, []),
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
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function branchUsers(?int $branchId, array $roles): Collection
    {
        if ($branchId === null) {
            return collect();
        }

        return $this->audience->resolve([
            'roles' => $roles,
            'branch_ids' => [$branchId],
            'include_company_wide' => false,
        ]);
    }
}
