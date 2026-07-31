<?php

namespace App\Services;

use App\Enums\SmsFailureReason;
use App\Models\SmsDeliveryAttempt;
use Illuminate\Support\Carbon;

/**
 * Answers "is SMS working right now, and if not, why".
 *
 * Two things are measured, and they answer different questions. The failure
 * *rate* over a window says how bad it is. The current *streak* — every attempt
 * since the last success — says what is wrong, because an outage that started an
 * hour ago should not be diagnosed from errors that predate the last message
 * that got through.
 */
class SmsHealthService
{
    /** Below this many attempts a rate is noise, so only systemic errors escalate. */
    private const MIN_SAMPLE = 5;

    private const CRITICAL_RATE = 50.0;

    private const WARNING_RATE = 20.0;

    /**
     * @return array<string, mixed>
     */
    public function check(int $windowHours = 24): array
    {
        $since = now()->subHours($windowHours);

        $sent = SmsDeliveryAttempt::where('created_at', '>=', $since)->where('succeeded', true)->count();
        $failed = SmsDeliveryAttempt::where('created_at', '>=', $since)->where('succeeded', false)->count();
        $total = $sent + $failed;

        $lastSuccessAt = SmsDeliveryAttempt::where('succeeded', true)->max('created_at');
        $lastFailureAt = SmsDeliveryAttempt::where('succeeded', false)->max('created_at');

        $streak = $this->currentStreak($lastSuccessAt);
        $reason = $this->dominantReason($streak);
        $rate = $total > 0 ? round(($failed / $total) * 100, 1) : 0.0;

        return [
            'status' => $this->status($total, $failed, $rate, $reason),
            'window_hours' => $windowHours,
            'sent' => $sent,
            'failed' => $failed,
            'failure_rate' => $rate,
            'consecutive_failures' => $streak->count(),
            'last_success_at' => $lastSuccessAt ? Carbon::parse($lastSuccessAt)->toIso8601String() : null,
            'last_failure_at' => $lastFailureAt ? Carbon::parse($lastFailureAt)->toIso8601String() : null,
            'reason' => $reason?->value,
            'reason_label' => $reason?->label(),
            'remedy' => $reason?->remedy(),
            'systemic' => (bool) $reason?->isSystemic(),
            'affected' => $this->affectedNotifications($since),
        ];
    }

    /**
     * Every failure since the last message that got through — i.e. what is
     * broken now, as opposed to what was broken at some point today.
     *
     * @return \Illuminate\Support\Collection<int, SmsDeliveryAttempt>
     */
    private function currentStreak(?string $lastSuccessAt): \Illuminate\Support\Collection
    {
        return SmsDeliveryAttempt::query()
            ->where('succeeded', false)
            ->when($lastSuccessAt, fn ($q) => $q->where('created_at', '>', $lastSuccessAt))
            ->orderByDesc('id')
            // A streak of 500 and a streak of 50,000 call for the same action;
            // the cap keeps this bounded on a pipe that has been dead for weeks.
            ->limit(500)
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SmsDeliveryAttempt>  $streak
     */
    private function dominantReason(\Illuminate\Support\Collection $streak): ?SmsFailureReason
    {
        if ($streak->isEmpty()) {
            return null;
        }

        $counts = $streak->countBy(fn (SmsDeliveryAttempt $a) => $a->failure_reason?->value ?? SmsFailureReason::Unknown->value);

        $top = $counts->sortDesc()->keys()->first();

        return SmsFailureReason::tryFrom((string) $top);
    }

    private function status(int $total, int $failed, float $rate, ?SmsFailureReason $reason): string
    {
        if ($total === 0 && $failed === 0) {
            // Nothing tried in the window. Silence is not health, but it is not
            // an outage either — say so rather than claim green.
            return $reason?->isSystemic() ? 'critical' : 'unknown';
        }

        // No credit / bad credentials / no config will fail every subsequent
        // message. One is enough; waiting for a rate to build is waiting for
        // customers to miss messages.
        if ($failed > 0 && $reason?->isSystemic()) {
            return 'critical';
        }

        if ($total < self::MIN_SAMPLE) {
            return $failed > 0 ? 'warning' : 'healthy';
        }

        return match (true) {
            $rate >= self::CRITICAL_RATE => 'critical',
            $rate >= self::WARNING_RATE => 'warning',
            default => 'healthy',
        };
    }

    /**
     * Which notifications are being lost — the business consequence, which is
     * what makes an alert worth reading.
     *
     * @return array<int, array<string, mixed>>
     */
    private function affectedNotifications(Carbon $since): array
    {
        return SmsDeliveryAttempt::query()
            ->where('created_at', '>=', $since)
            ->where('succeeded', false)
            ->whereNotNull('notification')
            ->selectRaw('notification, count(*) as failures')
            ->groupBy('notification')
            ->orderByDesc('failures')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'notification' => $row->notification,
                'failures' => (int) $row->failures,
            ])
            ->all();
    }
}
