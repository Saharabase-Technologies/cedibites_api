<?php

namespace App\Services;

use App\Models\AcknowledgedError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class SmartErrorService
{
    public function __construct(
        private readonly ErrorExplainer $explainer,
    ) {}

    /**
     * Get business-friendly error feed with categories and severity.
     *
     * @return array<string, mixed>
     */
    public function getFeed(int $limit = 50, bool $includeAcknowledged = false): array
    {
        $errors = collect();

        $errors = $errors
            ->merge($this->loginFailures())
            ->merge($this->failedJobs())
            ->merge($this->paymentErrors())
            ->merge($this->recentExceptions())
            ->sortByDesc('timestamp')
            ->values();

        $errors = $this->applyAcknowledgements($errors);

        $outstanding = $errors->reject(fn ($e) => $e['acknowledged'])->values();
        $acknowledged = $errors->filter(fn ($e) => $e['acknowledged'])->values();

        $visible = $includeAcknowledged ? $errors : $outstanding;

        return [
            'errors' => $visible->take($limit)->values()->toArray(),
            'summary' => $this->summary($outstanding) + [
                'acknowledged' => $acknowledged->count(),
            ],
        ];
    }

    /**
     * Stamp every error with its fingerprint and mark the ones already dealt with.
     *
     * An acknowledgement is a watermark, not a mute: it hides the fault only
     * while the newest occurrence is older than the moment somebody dismissed
     * it. The instant the same fault happens again it returns to the feed
     * unacknowledged, which is the whole point — the reader is meant to notice
     * new ones, not lose old ones.
     *
     * @param  Collection<int, array<string, mixed>>  $errors
     * @return Collection<int, array<string, mixed>>
     */
    private function applyAcknowledgements(Collection $errors): Collection
    {
        $errors = $errors->map(function (array $error) {
            $error['fingerprint'] = $this->fingerprint($error);

            return $error;
        });

        $acks = AcknowledgedError::query()
            ->whereIn('fingerprint', $errors->pluck('fingerprint')->unique()->all())
            ->get()
            ->keyBy('fingerprint');

        return $errors->map(function (array $error) use ($acks) {
            $ack = $acks->get($error['fingerprint']);

            $stillSilenced = $ack
                && Carbon::parse($error['timestamp'])->lte($ack->acknowledged_at);

            $error['acknowledged'] = (bool) $stillSilenced;
            $error['acknowledged_at'] = $stillSilenced ? $ack->acknowledged_at->toIso8601String() : null;
            $error['acknowledged_by'] = $stillSilenced ? $ack->acknowledgedBy?->name : null;

            return $error;
        });
    }

    /**
     * A key for "this same fault", stable across occurrences.
     *
     * The feed's own `id` cannot be used. Log-file exceptions carry a
     * positional index that shifts every time the log grows, and job ids and
     * attempt counts change on every recurrence — so acknowledging by `id`
     * would silence one sighting of a fault and let the next one through
     * looking brand new. Hashing the category with a title stripped of every
     * number, id and quoted value collapses all of that onto one key.
     */
    public function fingerprint(array $error): string
    {
        $title = mb_strtolower($error['title'] ?? '');

        $normalised = preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f-]{27,}/i', '/\d+/', '/[\'"][^\'"]*[\'"]/', '/\s+/'],
            ['<uuid>', '<n>', '<value>', ' '],
            $title
        );

        return hash('sha256', ($error['category'] ?? 'unknown').'|'.trim((string) $normalised));
    }

    /**
     * Detect repeated login failures and report them as friendly messages.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function loginFailures(): Collection
    {
        $since = now()->subHours(24);

        // Get login failure activities in the last 24 hours
        $failures = Activity::where('log_name', 'auth')
            ->whereIn('event', ['login_failed', 'staff_login_failed'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Group by identifier (phone/email) to detect patterns
        $grouped = $failures->groupBy(fn ($a) => $a->properties['identifier'] ?? $a->properties['phone'] ?? $a->subject_id ?? 'unknown');

        $errors = collect();

        foreach ($grouped as $identifier => $attempts) {
            // Check for burst patterns (3+ failures in 5 minutes)
            $recentAttempts = $attempts->filter(fn ($a) => $a->created_at->gt(now()->subMinutes(5)));

            $props = $attempts->first()->properties;
            $reason = $props['reason'] ?? null;
            $userName = $props['name'] ?? $props['identifier'] ?? $identifier;

            // Who they are, so the reader does not have to look up a phone number.
            $who = collect([
                $props['employee_no'] ?? null,
                $props['role'] ?? null,
                empty($props['branches']) ? null : implode(', ', (array) $props['branches']),
            ])->filter()->implode(' · ');

            if ($recentAttempts->count() >= 3) {
                $errors->push([
                    'id' => 'login-burst-'.$identifier,
                    'category' => 'authentication',
                    'severity' => 'warning',
                    'title' => "{$userName} failed to sign in {$recentAttempts->count()} times in 5 minutes",
                    'description' => $this->explainLoginReason($reason, $identifier, $who),
                    'cause' => $this->explainLoginReason($reason, $identifier, $who),
                    'fix' => $this->fixForLoginReason($reason),
                    'reason' => $reason,
                    'name' => $props['name'] ?? null,
                    'employee_no' => $props['employee_no'] ?? null,
                    'role' => $props['role'] ?? null,
                    'account_status' => $props['account_status'] ?? null,
                    'ips' => $attempts->pluck('properties.ip')->filter()->unique()->values()->all(),
                    'phone' => $identifier,
                    'timestamp' => $recentAttempts->first()->created_at->toIso8601String(),
                    'count' => $recentAttempts->count(),
                    'action' => 'reset_password',
                ]);
            } elseif ($attempts->count() >= 5) {
                $errors->push([
                    'id' => 'login-repeated-'.$identifier,
                    'category' => 'authentication',
                    'severity' => 'info',
                    'title' => "{$userName} has had {$attempts->count()} failed sign-ins today",
                    'description' => $this->explainLoginReason($reason, $identifier, $who),
                    'cause' => $this->explainLoginReason($reason, $identifier, $who),
                    'fix' => $this->fixForLoginReason($reason),
                    'reason' => $reason,
                    'name' => $props['name'] ?? null,
                    'employee_no' => $props['employee_no'] ?? null,
                    'role' => $props['role'] ?? null,
                    'account_status' => $props['account_status'] ?? null,
                    'ips' => $attempts->pluck('properties.ip')->filter()->unique()->values()->all(),
                    'phone' => $identifier,
                    'timestamp' => $attempts->first()->created_at->toIso8601String(),
                    'count' => $attempts->count(),
                    'action' => 'review',
                ]);
            }
        }

        // Also report total login failures as a summary
        if ($failures->count() > 0) {
            $errors->push([
                'id' => 'login-summary-'.now()->format('Y-m-d'),
                'category' => 'authentication',
                'severity' => 'info',
                'title' => "{$failures->count()} failed login attempts in the last 24 hours",
                'description' => "Across {$grouped->count()} different accounts.",
                'cause' => $this->describeLoginReasons($failures),
                'fix' => $this->adviseOnLoginReasons($failures),
                'accounts' => $this->accountBreakdown($grouped),
                'timestamp' => $failures->first()->created_at->toIso8601String(),
                'count' => $failures->count(),
            ]);
        }

        return $errors;
    }

    /**
     * One account's failure, in a sentence a manager can act on.
     */
    private function explainLoginReason(?string $reason, string $identifier, string $who): string
    {
        $suffix = $who === '' ? '' : " ({$who})";

        return match ($reason) {
            'wrong_password' => "The account exists but the password was wrong{$suffix}. Almost always a forgotten password rather than anything sinister.",
            'unknown_account' => "No staff account exists for {$identifier}. Either they are typing the wrong phone or email, or someone is guessing at addresses.",
            'no_employee_record' => "The password was correct, but this person has no staff record{$suffix}, so the staff app will not let them in.",
            'account_suspended' => "The password was correct but the account is suspended{$suffix}, so sign-in was refused.",
            'account_inactive' => "The password was correct but the account is not active{$suffix}, so sign-in was refused.",
            default => "Repeated failed sign-ins for {$identifier}{$suffix}. This attempt predates reason tracking, so the cause was not recorded.",
        };
    }

    private function fixForLoginReason(?string $reason): string
    {
        return match ($reason) {
            'wrong_password' => 'Reset their password from Platform → Passwords, then read them the new one. They must change it at first sign-in.',
            'unknown_account' => 'Confirm the phone number or email on their staff record. If you do not recognise the identifier at all, treat it as an intrusion attempt and note the IP below.',
            'no_employee_record' => 'They have a customer account, not a staff one. Create a staff record for them, or point them at the customer app instead.',
            'account_suspended', 'account_inactive' => 'If they should be working, set the account back to Active on their staff record. If the suspension was intended, tell them so they stop trying.',
            default => 'Ask the person what they are seeing. Future attempts will record the exact reason.',
        };
    }

    /**
     * Say what actually went wrong, grouped by cause.
     *
     * "3 failed logins" is a number, not information. Whether those were one
     * person mistyping a password, a suspended account still trying to get in,
     * or attempts against addresses that do not exist calls for three completely
     * different responses.
     *
     * @param  Collection<int, Activity>  $failures
     */
    private function describeLoginReasons(Collection $failures): string
    {
        $byReason = $failures->countBy(fn ($a) => $a->properties['reason'] ?? 'unrecorded');

        $phrase = [
            'wrong_password' => 'wrong password on an account that exists',
            'unknown_account' => 'no account with that phone or email',
            'no_employee_record' => 'signed in but has no staff record',
            'account_suspended' => 'account suspended',
            'account_inactive' => 'account not active',
            'unrecorded' => 'recorded before reasons were tracked',
        ];

        $parts = $byReason
            ->map(fn ($count, $reason) => $count.' × '.($phrase[$reason] ?? str_replace('_', ' ', $reason)))
            ->values()
            ->all();

        return implode('; ', $parts).'.';
    }

    /**
     * @param  Collection<int, Activity>  $failures
     */
    private function adviseOnLoginReasons(Collection $failures): string
    {
        $reasons = $failures->map(fn ($a) => $a->properties['reason'] ?? null)->filter()->unique();

        $advice = [];

        if ($reasons->contains('wrong_password')) {
            $advice[] = 'For staff who forgot a password, reset it from Platform → Passwords.';
        }

        if ($reasons->contains('unknown_account')) {
            $advice[] = 'Attempts against addresses with no account are usually a staff member using the wrong phone number — check the identifier below before assuming an intrusion.';
        }

        if ($reasons->contains('no_employee_record')) {
            $advice[] = 'Someone with a customer account is trying the staff app; they need a staff record before they can sign in.';
        }

        if ($reasons->filter(fn ($r) => str_starts_with((string) $r, 'account_'))->isNotEmpty()) {
            $advice[] = 'A suspended or inactive account is still trying to sign in — reactivate it if that was not intended.';
        }

        return $advice === []
            ? 'Nothing to act on unless the same account keeps appearing.'
            : implode(' ', $advice);
    }

    /**
     * Per-account detail: who, how many times, why, and from where.
     *
     * @param  Collection<string, Collection<int, Activity>>  $grouped
     * @return array<int, array<string, mixed>>
     */
    private function accountBreakdown(Collection $grouped): array
    {
        return $grouped
            ->map(function (Collection $attempts, string $identifier) {
                $latest = $attempts->first();
                $props = $latest->properties;

                return [
                    'identifier' => $identifier,
                    'name' => $props['name'] ?? null,
                    'employee_no' => $props['employee_no'] ?? null,
                    'role' => $props['role'] ?? null,
                    'branches' => $props['branches'] ?? [],
                    'account_status' => $props['account_status'] ?? null,
                    'reason' => $props['reason'] ?? null,
                    'attempts' => $attempts->count(),
                    'ips' => $attempts->pluck('properties.ip')->filter()->unique()->values()->all(),
                    'last_attempt' => $latest->created_at->toIso8601String(),
                ];
            })
            ->sortByDesc('attempts')
            ->values()
            ->all();
    }

    /**
     * Get failed queue jobs translated into business language.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function failedJobs(): Collection
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(30)
            ->get();

        return $jobs->map(function ($job) {
            $payload = json_decode($job->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown Job';
            $shortName = class_basename($jobClass);

            $category = $this->categorizeJob($shortName);
            $description = $this->describeJobFailure($shortName, $job->exception);

            return [
                'id' => 'failed-job-'.$job->id,
                'category' => $category,
                'severity' => 'error',
                'title' => $description['title'],
                'description' => $description['detail'],
                'timestamp' => Carbon::parse($job->failed_at)->toIso8601String(),
                'job_id' => $job->id,
                'action' => 'retry_job',
            ];
        });
    }

    /**
     * Detect payment-related errors from activity logs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentErrors(): Collection
    {
        $since = now()->subHours(24);

        $errors = Activity::where('log_name', 'payment')
            ->whereIn('event', ['payment_failed', 'callback_error', 'rmp_failed'])
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return $errors->map(function ($activity) {
            $props = $activity->properties->toArray();
            $orderNumber = $props['order_number'] ?? 'unknown';
            $method = $props['payment_method'] ?? 'unknown';
            $reason = $props['error'] ?? $props['reason'] ?? 'Unknown error';

            $title = match ($activity->event) {
                'payment_failed' => "Payment failed for order #{$orderNumber}",
                'callback_error' => "Payment callback error on order #{$orderNumber}",
                'rmp_failed' => "MoMo payment prompt failed for order #{$orderNumber}",
                default => "Payment issue on order #{$orderNumber}",
            };

            return [
                'id' => 'payment-'.$activity->id,
                'category' => 'payments',
                'severity' => 'error',
                'title' => $title,
                'description' => "Method: {$method}. Reason: {$reason}",
                'timestamp' => $activity->created_at->toIso8601String(),
                'action' => 'view_order',
                'order_number' => $orderNumber,
            ];
        });
    }

    /**
     * Read recent Laravel log file for exceptions.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentExceptions(): Collection
    {
        $logPath = storage_path('logs/laravel.log');

        if (! file_exists($logPath)) {
            return collect();
        }

        // Read last 50KB of the log file
        $handle = fopen($logPath, 'r');
        if (! $handle) {
            return collect();
        }

        $fileSize = filesize($logPath);
        $readSize = min($fileSize, 50 * 1024);
        fseek($handle, -$readSize, SEEK_END);
        $content = fread($handle, $readSize);
        fclose($handle);

        if (! $content) {
            return collect();
        }

        // Parse log entries — match [YYYY-MM-DD HH:MM:SS] environment.ERROR:
        preg_match_all(
            '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|EMERGENCY): (.+?)(?=\n\[|\z)/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $errors = collect();
        $cutoff = now()->subHours(24);

        foreach (array_slice($matches, -20) as $i => $match) {
            $timestamp = Carbon::parse($match[1]);
            if ($timestamp->lt($cutoff)) {
                continue;
            }

            $level = strtolower($match[2]);
            $message = trim(explode("\n", $match[3])[0]);
            $translated = $this->translateException($message);
            $explained = $this->explainer->explain($message, $translated['category']);

            $errors->push([
                'id' => 'exception-'.$i.'-'.$timestamp->timestamp,
                'category' => $explained['category'],
                'severity' => $level === 'critical' || $level === 'emergency' ? 'critical' : 'error',
                'title' => $explained['title'],
                // Kept for older clients that only render `description`.
                'description' => $explained['cause'],
                'cause' => $explained['cause'],
                'fix' => $explained['fix'],
                'explanation_source' => $explained['source'],
                'timestamp' => $timestamp->toIso8601String(),
                'raw' => mb_substr($message, 0, 200),
            ]);
        }

        return $errors;
    }

    private function categorizeJob(string $shortName): string
    {
        return match (true) {
            str_contains($shortName, 'Payment'), str_contains($shortName, 'Hubtel') => 'payments',
            str_contains($shortName, 'Notification'), str_contains($shortName, 'Mail'), str_contains($shortName, 'Sms') => 'notifications',
            str_contains($shortName, 'Order') => 'orders',
            default => 'system',
        };
    }

    /**
     * @return array{title: string, detail: string}
     */
    private function describeJobFailure(string $shortName, string $exception): array
    {
        $firstLine = trim(explode("\n", $exception)[0]);

        return match (true) {
            str_contains($shortName, 'Payment') => [
                'title' => 'A payment processing job failed',
                'detail' => "The system couldn't complete a payment task. Error: ".mb_substr($firstLine, 0, 150),
            ],
            str_contains($shortName, 'Notification'), str_contains($shortName, 'Mail') => [
                'title' => 'A notification failed to send',
                'detail' => "An email or SMS notification couldn't be delivered. ".mb_substr($firstLine, 0, 150),
            ],
            str_contains($shortName, 'Sms') => [
                'title' => 'An SMS failed to send',
                'detail' => 'The SMS gateway rejected or timed out. '.mb_substr($firstLine, 0, 150),
            ],
            str_contains($shortName, 'Order') => [
                'title' => 'An order processing job failed',
                'detail' => 'A background order task encountered an error. '.mb_substr($firstLine, 0, 150),
            ],
            default => [
                'title' => "{$shortName} job failed",
                'detail' => mb_substr($firstLine, 0, 200),
            ],
        };
    }

    /**
     * Translate raw exception messages into business-friendly language.
     *
     * @return array{category: string, title: string, detail: string}
     */
    private function translateException(string $message): array
    {
        return match (true) {
            str_contains($message, 'SQLSTATE') => [
                'category' => 'database',
                'title' => 'Database query error detected',
                'detail' => 'A database operation failed — this may affect order processing or data retrieval.',
            ],
            str_contains($message, 'cURL error'), str_contains($message, 'ConnectionException') => [
                'category' => 'integrations',
                'title' => 'External service connection failed',
                'detail' => 'The system couldn\'t reach an external API (Hubtel, SMS gateway, etc.).',
            ],
            str_contains($message, 'HubtelSmsService'), str_contains($message, 'Invalid phone number format') => [
                'category' => 'integrations',
                'title' => 'SMS notification failed',
                'detail' => 'An SMS could not be sent — the customer\'s phone number may be in an invalid format.',
            ],
            str_contains($message, 'Hubtel'), str_contains($message, 'hubtel') => [
                'category' => 'payments',
                'title' => 'Hubtel payment gateway error',
                'detail' => 'A payment-related API call to Hubtel returned an error.',
            ],
            str_contains($message, 'Too Many Attempts'), str_contains($message, 'ThrottleRequests') => [
                'category' => 'security',
                'title' => 'Rate limit triggered',
                'detail' => 'Someone or something is making too many requests — could be a brute-force attempt.',
            ],
            str_contains($message, 'Unauthenticated'), str_contains($message, 'AuthenticationException') => [
                'category' => 'authentication',
                'title' => 'Unauthenticated access attempt',
                'detail' => 'A request was made to a protected route without valid credentials.',
            ],
            str_contains($message, 'NotFoundHttpException'), str_contains($message, '404') => [
                'category' => 'system',
                'title' => 'Page or API route not found',
                'detail' => 'A request was made to a URL that doesn\'t exist — may be a broken link or a bot.',
            ],
            str_contains($message, 'MethodNotAllowedHttpException') => [
                'category' => 'system',
                'title' => 'Wrong HTTP method used',
                'detail' => 'A request used GET instead of POST (or vice versa) — likely a frontend bug.',
            ],
            str_contains($message, 'ValidationException') => [
                'category' => 'system',
                'title' => 'Data validation failed',
                'detail' => 'A form or API request sent invalid data that didn\'t pass validation rules.',
            ],
            default => [
                'category' => 'system',
                'title' => 'Application error detected',
                'detail' => mb_substr($message, 0, 200),
            ],
        };
    }

    /**
     * @return array<string, int>
     */
    private function summary(Collection $errors): array
    {
        return [
            'total' => $errors->count(),
            'critical' => $errors->where('severity', 'critical')->count(),
            'errors' => $errors->where('severity', 'error')->count(),
            'warnings' => $errors->where('severity', 'warning')->count(),
            'info' => $errors->where('severity', 'info')->count(),
            'by_category' => $errors->countBy('category')->toArray(),
        ];
    }
}
