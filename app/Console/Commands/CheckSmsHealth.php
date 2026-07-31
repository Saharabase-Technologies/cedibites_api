<?php

namespace App\Console\Commands;

use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Notifications\SmsHealthAlertNotification;
use App\Services\SmsHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Watches SMS delivery and tells the tech admin when it breaks.
 *
 * Written because prod SMS died on a Hubtel billing rejection and nobody found
 * out for three weeks: forgotPassword answers "you will receive a code shortly"
 * whether or not it sent (correct, it must not leak which accounts exist), so a
 * dead pipe looks exactly like a working one from every screen in the product.
 * The only place the truth existed was the log.
 *
 * Alerts are stateful, not per-run: one alert per incident, a fresh one if the
 * cause changes, a reminder once the cooldown lapses, and a recovery notice when
 * it starts working again. A monitor that emails every fifteen minutes gets
 * filtered, and then it is not a monitor.
 */
class CheckSmsHealth extends Command
{
    protected $signature = 'sms:health-check
                            {--window=24 : Hours of history to judge}
                            {--force : Alert even if the cooldown has not lapsed}
                            {--prune-days=30 : Delete attempt rows older than this}
                            {--dry : Report only; send nothing}';

    protected $description = 'Check SMS delivery health and alert platform admins when it is failing';

    private const STATE_KEY = 'sms_health.incident';

    public function handle(SmsHealthService $healthService): int
    {
        $health = $healthService->check((int) $this->option('window'));

        $this->line("status: <options=bold>{$health['status']}</>");
        $this->line("  {$health['sent']} sent / {$health['failed']} failed ({$health['failure_rate']}%) in {$health['window_hours']}h");
        $this->line('  reason: '.($health['reason_label'] ?? 'none'));
        $this->line('  last success: '.($health['last_success_at'] ?? 'none on record'));

        $incident = Cache::get(self::STATE_KEY);
        $failing = in_array($health['status'], ['critical', 'warning'], true);

        if (! $this->option('dry')) {
            if ($failing && $this->shouldAlert($health, $incident)) {
                $this->dispatch($health, recovered: false);

                Cache::put(self::STATE_KEY, [
                    'reason' => $health['reason'],
                    'status' => $health['status'],
                    'alerted_at' => now()->toIso8601String(),
                ], now()->addDays(30));
            } elseif (! $failing && $incident) {
                // Only send a recovery notice if we actually raised an alarm.
                $this->dispatch($health, recovered: true);
                Cache::forget(self::STATE_KEY);
                $this->info('  recovery notice sent');
            }
        }

        $this->prune();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $health
     * @param  array<string, mixed>|null  $incident
     */
    private function shouldAlert(array $health, ?array $incident): bool
    {
        if ($this->option('force') || $incident === null) {
            return true;
        }

        // A different cause is a different incident — say so immediately rather
        // than sitting on it because an unrelated alert went out an hour ago.
        if (($incident['reason'] ?? null) !== $health['reason']) {
            return true;
        }

        // Escalation is news too.
        if (($incident['status'] ?? null) !== 'critical' && $health['status'] === 'critical') {
            return true;
        }

        $cooldown = (int) config('services.sms.alert_cooldown_hours', 6);

        return isset($incident['alerted_at'])
            && now()->diffInHours(\Illuminate\Support\Carbon::parse($incident['alerted_at']), absolute: true) >= $cooldown;
    }

    /**
     * @param  array<string, mixed>  $health
     */
    private function dispatch(array $health, bool $recovered): void
    {
        $notification = new SmsHealthAlertNotification($health, $recovered);

        $users = $this->recipients();
        $extraEmails = $this->configuredEmails();

        if ($users->isEmpty() && $extraEmails === []) {
            // Nobody to tell. Loud in the log, because a monitor with no
            // recipients is indistinguishable from a healthy system.
            Log::error('SMS health alert has no recipients', [
                'status' => $health['status'],
                'reason' => $health['reason'],
                'hint' => 'Grant view_system_health to a user, or set SMS_ALERT_EMAILS.',
            ]);
            $this->error('  no recipients — set SMS_ALERT_EMAILS or grant view_system_health to someone');

            return;
        }

        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);
        }

        foreach ($extraEmails as $email) {
            Notification::route('mail', $email)->notify($notification);
        }

        $this->info(sprintf(
            '  alert sent to %d user(s)%s',
            $users->count(),
            $extraEmails === [] ? '' : ' and '.count($extraEmails).' configured address(es)',
        ));
    }

    /**
     * Whoever can read system health can be told it is broken. Keyed on the
     * permission rather than the tech_admin role so the alert still lands if the
     * roles are ever rearranged.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(): \Illuminate\Support\Collection
    {
        try {
            return User::permission('view_system_health')->get();
        } catch (\Throwable $e) {
            Log::warning('Could not resolve SMS alert recipients by permission', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return array<int, string>
     */
    private function configuredEmails(): array
    {
        $raw = (string) config('services.sms.alert_emails', '');

        return collect(explode(',', $raw))
            ->map(fn ($e) => trim($e))
            ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();
    }

    private function prune(): void
    {
        $days = (int) $this->option('prune-days');

        if ($days <= 0) {
            return;
        }

        $deleted = SmsDeliveryAttempt::where('created_at', '<', now()->subDays($days))->delete();

        if ($deleted > 0) {
            $this->line("  pruned {$deleted} attempt row(s) older than {$days}d");
        }
    }
}
