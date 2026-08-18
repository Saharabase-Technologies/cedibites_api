<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Order;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\MessageMeter;

/**
 * What a rule WOULD have done, against real history, sending nothing.
 *
 * This is how you find the rule that fires on every order before it fires on
 * every order. A rule reads reasonably right up until it is pointed at four
 * thousand real ones, and the difference between "matched 412 times" and "would
 * have sent 47" is the entire value of the cooldown — invisible until somebody
 * counts it.
 *
 * The cooldown is simulated FORWARD THROUGH THE WINDOW rather than read from the
 * live log: orders are replayed oldest first, and a would-be send blocks the
 * next one for this rule the same way a real send would.
 *
 * What that deliberately does NOT model is other rules. The real cooldown is
 * global, so a rule that would send 47 times on its own will send fewer than 47
 * alongside anything else already live. The figure here is therefore a CEILING,
 * which is the safe direction for a number somebody is about to approve.
 */
class AutomationDryRun
{
    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly MessageMeter $meter,
        private readonly EventMatcher $events,
    ) {}

    /**
     * @return array{
     *     days: int, orders_examined: int, matched: int, would_send: int,
     *     suppressed: array<string, int>, estimated_cost: float,
     *     segments_per_message: int, people_reached: int,
     *     busiest_recipient: int, sample: array<int, array>
     * }
     */
    public function run(AutomationRule $rule, ?int $days = null): array
    {
        $days = $days ?? (int) config('automation.dry_run_days', 30);
        $guard = new AutomationGuard;

        $matched = 0;
        $wouldSend = 0;
        $examined = 0;
        $suppressed = [];
        $sample = [];

        /** @var array<string, \Illuminate\Support\Carbon> last simulated send per phone */
        $lastSent = [];
        /** @var array<string, int> how many this rule would have sent each person */
        $perPhone = [];

        Order::whereIn('status', ['completed', 'delivered'])
            ->where('created_at', '>=', now()->subDays($days))
            // Oldest first: the cooldown only means anything if the replay runs
            // in the direction time does.
            ->orderBy('created_at')
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (
                $rule, $guard, &$matched, &$wouldSend, &$examined,
                &$suppressed, &$sample, &$lastSent, &$perPhone
            ): void {
                foreach ($orders as $order) {
                    $examined++;

                    $milestones = new OrderMilestones($order);
                    $phone = $milestones->phone();

                    if ($phone === null || ! $this->events->matches($rule, $milestones)) {
                        continue;
                    }

                    $profile = $this->audience->profileFromOrders(
                        [$order->loadMissing('items:id,order_id,menu_item_id,menu_item_option_id'), ...$milestones->previousOrders()],
                        $order->contact_name,
                    );

                    if (! $this->audience->profileMatches($profile, $phone, $rule->rules())) {
                        continue;
                    }

                    $matched++;

                    $reason = $this->simulatedObjection($rule, $guard, $phone, $lastSent, $perPhone, $order);

                    if ($reason !== null) {
                        $suppressed[$reason] = ($suppressed[$reason] ?? 0) + 1;

                        continue;
                    }

                    $wouldSend++;
                    $lastSent[$phone] = $order->created_at;
                    $perPhone[$phone] = ($perPhone[$phone] ?? 0) + 1;

                    // A handful of real examples. "412 matched" is a number;
                    // five names and dates is something an operator can check.
                    if (count($sample) < 10) {
                        $sample[] = [
                            'phone' => $phone,
                            'name' => $order->contact_name,
                            'order_id' => $order->id,
                            'at' => $order->created_at?->toIso8601String(),
                        ];
                    }
                }
            });

        $segments = $this->meter->segments($rule->message);

        return [
            'days' => $days,
            'orders_examined' => $examined,
            'matched' => $matched,
            'would_send' => $wouldSend,
            'suppressed' => $suppressed,

            'segments_per_message' => $segments,
            'estimated_cost' => round(
                $segments * $wouldSend * (float) config('automation.rate_per_segment', 0.0243),
                4,
            ),

            'people_reached' => count($perPhone),

            /*
             * The most any one person would have received.
             *
             * The figure that says whether the cooldown is set right. A rule
             * that reaches 300 people 47 times is fine; a rule that reaches
             * 3 people 47 times is a rule about to annoy three customers into
             * never ordering again, and the totals alone cannot tell them apart.
             */
            'busiest_recipient' => $perPhone === [] ? 0 : max($perPhone),

            'sample' => $sample,
        ];
    }

    /**
     * The guards, applied against the simulated timeline rather than the live log.
     *
     * Sampling and the lifetime cap come from the real guard — sampling is
     * stable per person so it gives the same answer here as it will in
     * production, which is exactly why it was built that way.
     */
    private function simulatedObjection(
        AutomationRule $rule,
        AutomationGuard $guard,
        string $phone,
        array $lastSent,
        array $perPhone,
        Order $order,
    ): ?string {
        if (! $guard->isSampled($rule, $phone)) {
            return \App\Models\AutomationFire::NOT_SAMPLED;
        }

        if ($rule->max_per_customer !== null && ($perPhone[$phone] ?? 0) >= $rule->max_per_customer) {
            return \App\Models\AutomationFire::LIFETIME_CAP;
        }

        $previous = $lastSent[$phone] ?? null;

        if ($previous && $order->created_at
            && $previous->diffInDays($order->created_at) < $rule->effectiveCooldownDays()) {
            return \App\Models\AutomationFire::COOLDOWN;
        }

        return null;
    }
}
