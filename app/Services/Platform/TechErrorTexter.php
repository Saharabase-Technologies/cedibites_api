<?php

namespace App\Services\Platform;

use App\Enums\EmployeeStatus;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\TechErrorTextNotification;
use App\Services\SmartErrorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Texts the tech admin about faults on the error feed.
 *
 * The error page is only any use to somebody looking at it. This puts each
 * fault on the tech admin's phone while it is still happening, then leaves it
 * alone unless it keeps happening.
 *
 *   A new fault is texted straight away.
 *   The same fault is texted again as a reminder once the repeat window has
 *   passed (three hours by default), and only if it has happened since. A
 *   fault that stopped is not chased.
 *   Failed staff sign-ins come as one round-up, at most every six hours:
 *   who, how many times, and why. Three in five minutes is texted at once,
 *   because that is the error page's own warning for somebody guessing.
 *
 * It reads the same feed the page shows, so a fault acknowledged on the page
 * is not texted, and the text says exactly what the page will explain. Two
 * checks the feed does not make are added: a queue nobody is working, and a
 * disk nearly full.
 *
 * Every text is written to the activity log, and that record is also the
 * memory: it is how a fault already texted is recognised and how the day's
 * count is kept. It lives in the database, not the cache, because every deploy
 * clears the cache and a cleared cache would re-send everything.
 *
 * Never texted:
 *   - Anything the page grades below a warning.
 *   - SMS faults. A text cannot report the line it would travel on. The SMS
 *     health check emails those instead.
 *   - Console typing mistakes (PsySH parse errors from somebody in tinker).
 *   - The database refusing connections between 06:00 and 07:00. That is the
 *     server patching itself and restarting Postgres, which takes a second.
 */
class TechErrorTexter
{
    public const LOG_NAME = 'platform';

    public const EVENT_TEXTED = 'tech_error_texted';

    public const EVENT_SIGN_IN_ROUNDUP = 'tech_sign_in_roundup_texted';

    public const EVENT_CAP_REACHED = 'tech_error_cap_reached';

    private const TEXTABLE = ['warning', 'error', 'critical'];

    private const SEVERITY_RANK = ['critical' => 3, 'error' => 2, 'warning' => 1];

    private const SIGN_IN_REASONS = [
        'wrong_password' => 'wrong password',
        'unknown_account' => 'no such account',
        'no_employee_record' => 'no staff record',
        'account_suspended' => 'account suspended',
        'account_terminated' => 'account ended',
        'account_on_leave' => 'on leave',
    ];

    public function __construct(
        private readonly SmartErrorService $feed,
        private readonly RuntimeSettings $settings,
    ) {}

    /**
     * Text whatever is due.
     *
     * A dry run decides everything the same way and sends nothing, and it runs
     * whether or not texts are switched on, so the switch can be checked before
     * it is flipped.
     *
     * @return array{enabled: bool, recipients: int, faults: list<array<string, mixed>>}
     */
    public function run(bool $dry = false): array
    {
        $enabled = (bool) $this->settings->get('alerts.tech_error_texts');

        if (! $enabled && ! $dry) {
            return ['enabled' => false, 'recipients' => 0, 'faults' => []];
        }

        $now = now();
        $cap = max(1, (int) $this->settings->get('alerts.tech_error_daily_cap'));
        $recipients = $this->recipients();
        $textedToday = $this->textsSince($now->copy()->startOfDay());

        [$report, $due] = $this->triage($now);

        if ($roundUp = $this->signInRoundUp($now)) {
            $due[] = $roundUp;
        }

        $heldByCap = 0;

        foreach ($due as $item) {
            if ($recipients->isEmpty()) {
                $report[] = $this->outcome($item, 'nobody to text');

                continue;
            }

            if ($textedToday >= $cap) {
                $heldByCap++;
                $report[] = $this->outcome($item, 'over daily cap');

                continue;
            }

            if ($dry) {
                $textedToday++;
                $report[] = $this->outcome($item, 'would text');

                continue;
            }

            if ($this->send($recipients, $item['message']) === 0) {
                $report[] = $this->outcome($item, 'send failed');

                continue;
            }

            $this->record($item['event'], $item['message'], $recipients->count(), $item['record']);
            $textedToday++;
            $report[] = $this->outcome($item, 'texted');
        }

        // Twenty texts and then silence reads as twenty faults. Twenty and
        // "six more are waiting" reads as a bad day, which is the truth. Once
        // a day, so the notice cannot become the flood it is warning about.
        if ($heldByCap > 0 && ! $dry && ! $this->loggedSince(self::EVENT_CAP_REACHED, $now->copy()->startOfDay())) {
            $this->sendCapNotice($recipients, $cap, $heldByCap);
        }

        return ['enabled' => $enabled, 'recipients' => $recipients->count(), 'faults' => $report];
    }

    /**
     * Sort every fault on the feed into due and not due.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function triage(Carbon $now): array
    {
        $repeatHours = max(1, (int) $this->settings->get('alerts.tech_error_repeat_hours'));
        $lookback = $now->copy()->subMinutes(max(5, (int) config('alerts.tech_errors.lookback_minutes', 30)));

        $report = [];
        $due = [];

        foreach ($this->faults($now) as $fault) {
            $previous = $this->lastText($fault['fingerprint']);

            if ($previous === null) {
                // Only news is texted, so the first run after a deploy does
                // not send the whole of yesterday.
                if ($fault['last_seen']->lt($lookback)) {
                    continue;
                }

                $due[] = $this->firstText($fault);

                continue;
            }

            if ($previous->created_at->gt($now->copy()->subHours($repeatHours))) {
                $report[] = $this->outcome($fault, 'already texted');

                continue;
            }

            $texted = Carbon::parse($previous->properties['last_seen_at'] ?? $previous->created_at);
            $since = $fault['sightings']->filter(fn (array $s) => $s['at']->gt($texted));

            if ($since->isEmpty()) {
                $report[] = $this->outcome($fault, 'stopped');

                continue;
            }

            $due[] = $this->reminder($fault, $since, $previous->created_at);
        }

        return [$report, $due];
    }

    /**
     * Faults on the feed, one entry per fingerprint, worst and newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function faults(Carbon $now): Collection
    {
        return $this->feedItems()
            ->merge($this->healthItems($now))
            ->filter(fn (array $e) => in_array($e['severity'] ?? null, self::TEXTABLE, true))
            ->map(fn (array $e) => $e + [
                'fingerprint' => $this->feed->fingerprint($e),
                'seen_at' => Carbon::parse($e['timestamp']),
            ])
            ->reject(fn (array $e) => $this->isNoise($e))
            ->groupBy('fingerprint')
            ->map(function (Collection $group, string $fingerprint) {
                $latest = $group->sortByDesc(fn ($e) => $e['seen_at'])->first();

                return [
                    'fingerprint' => $fingerprint,
                    'title' => (string) $latest['title'],
                    'category' => $latest['category'] ?? 'system',
                    'severity' => $latest['severity'],
                    'last_seen' => $latest['seen_at'],
                    // A login burst is one feed item standing for several
                    // attempts; everything else is one item per sighting.
                    'sightings' => $group->map(fn ($e) => [
                        'at' => $e['seen_at'],
                        'count' => max(1, (int) ($e['count'] ?? 1)),
                    ])->sortBy(fn ($s) => $s['at'])->values(),
                ];
            })
            ->sort(fn (array $a, array $b) => [self::SEVERITY_RANK[$b['severity']] ?? 0, $b['last_seen']->getTimestamp()]
                <=> [self::SEVERITY_RANK[$a['severity']] ?? 0, $a['last_seen']->getTimestamp()])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $fault
     * @return array<string, mixed>
     */
    private function firstText(array $fault): array
    {
        $count = $fault['sightings']->sum('count');
        $seen = $count > 1
            ? "Seen {$count} times since {$this->clock($fault['sightings']->first()['at'])}."
            : "Seen at {$this->clock($fault['last_seen'])}.";

        return $this->due($fault, "{$this->title($fault['title'])} {$seen}", self::EVENT_TEXTED);
    }

    /**
     * @param  array<string, mixed>  $fault
     * @param  Collection<int, array<string, mixed>>  $since
     * @return array<string, mixed>
     */
    private function reminder(array $fault, Collection $since, Carbon $lastTexted): array
    {
        $count = $since->sum('count');
        $seen = $count > 1
            ? "Happened {$count} more times since {$this->clock($lastTexted)}."
            : "Happened again at {$this->clock($since->last()['at'])}.";

        return $this->due($fault, "Still happening: {$this->title($fault['title'])} {$seen}", self::EVENT_TEXTED);
    }

    /**
     * @param  array<string, mixed>  $fault
     * @return array<string, mixed>
     */
    private function due(array $fault, string $body, string $event): array
    {
        return [
            'title' => $fault['title'],
            'severity' => $fault['severity'],
            'event' => $event,
            'message' => "CediBites{$this->environmentTag()}: {$body} {$this->pageUrl()}",
            'record' => [
                'fingerprint' => $fault['fingerprint'],
                'title' => $fault['title'],
                'category' => $fault['category'],
                'severity' => $fault['severity'],
                'occurrences' => $fault['sightings']->sum('count'),
                'last_seen_at' => $fault['last_seen']->toIso8601String(),
            ],
        ];
    }

    /**
     * Failed staff sign-ins since the last round-up, by person and reason.
     *
     * One wrong password is not worth a text on its own, but the tech admin
     * wants to know who is struggling to get in and why, before they ring up.
     * So they are gathered, and sent at most once per window. The first one
     * after a quiet spell goes out on the next run, which is when it is most
     * useful; anything after that waits for the window to close.
     *
     * @return array<string, mixed>|null
     */
    private function signInRoundUp(Carbon $now): ?array
    {
        $windowHours = max(1, (int) $this->settings->get('alerts.sign_in_roundup_hours'));
        $previous = $this->lastLogged(self::EVENT_SIGN_IN_ROUNDUP);

        if ($previous && $previous->created_at->gt($now->copy()->subHours($windowHours))) {
            return null;
        }

        $since = $previous
            ? Carbon::parse($previous->properties['until'] ?? $previous->created_at)
            : $now->copy()->subHours($windowHours);

        $failures = ActivityLog::query()
            ->where('log_name', 'auth')
            ->where('event', 'staff_login_failed')
            ->where('created_at', '>', $since)
            ->where('created_at', '<=', $now)
            ->orderBy('created_at')
            ->get();

        if ($failures->isEmpty()) {
            return null;
        }

        $people = $failures
            ->groupBy(fn ($a) => $a->properties['user_id'] ?? $a->properties['identifier'] ?? 'unknown')
            ->map(function (Collection $attempts) {
                $props = $attempts->first()->properties;
                $reasons = $attempts
                    ->map(fn ($a) => self::SIGN_IN_REASONS[$a->properties['reason'] ?? ''] ?? str_replace('_', ' ', (string) ($a->properties['reason'] ?? 'unknown')))
                    ->unique()
                    ->implode(', ');

                return [
                    'who' => (string) ($props['name'] ?? $props['identifier'] ?? 'Someone'),
                    'count' => $attempts->count(),
                    'reasons' => $reasons,
                ];
            })
            ->sortByDesc('count')
            ->values();

        $named = $people->take(3)->map(fn ($p) => "{$p['who']} {$p['count']} ({$p['reasons']})")->implode(', ');
        $others = $people->count() - 3;
        if ($others > 0) {
            $named .= ", and {$others} ".Str::plural('other', $others);
        }

        $total = $failures->count();
        $body = sprintf(
            '%d failed staff %s since %s. %s.',
            $total,
            Str::plural('sign-in', $total),
            $this->clock($failures->first()->created_at),
            Str::ascii($named),
        );

        return [
            'title' => "{$total} failed staff ".Str::plural('sign-in', $total),
            'severity' => 'warning',
            'event' => self::EVENT_SIGN_IN_ROUNDUP,
            'message' => "CediBites{$this->environmentTag()}: {$body} {$this->pageUrl()}",
            'record' => ['until' => $failures->last()->created_at->toIso8601String(), 'failures' => $total],
        ];
    }

    /**
     * The page's own list: outstanding only, so acknowledging a fault on the
     * page also stops it being texted until it happens again.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function feedItems(): Collection
    {
        try {
            return collect($this->feed->getFeed(200)['errors'] ?? []);
        } catch (\Throwable $e) {
            // The feed reads the log file and three tables. If it cannot, the
            // health checks below can still report something.
            Log::warning('Tech error texts could not read the error feed', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function healthItems(Carbon $now): Collection
    {
        return collect([
            $this->stalledQueue($now),
            $this->fullDisk($now),
        ])->filter()->values();
    }

    /**
     * Jobs that became due and nobody picked up.
     *
     * Only meaningful for the database queue, which is what prod runs. A
     * delayed job is not stalled until it is due, so the clock starts at
     * `available_at`, not `created_at`.
     *
     * @return array<string, mixed>|null
     */
    private function stalledQueue(Carbon $now): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $minutes = max(1, (int) config('alerts.tech_errors.queue_stalled_minutes', 10));

        try {
            $stalled = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now->copy()->subMinutes($minutes)->getTimestamp());

            $count = (clone $stalled)->count();
            if ($count === 0) {
                return null;
            }

            $oldest = (int) (clone $stalled)->min('available_at');
        } catch (\Throwable $e) {
            Log::warning('Tech error texts could not read the jobs table', ['error' => $e->getMessage()]);

            return null;
        }

        $waited = intdiv($now->getTimestamp() - $oldest, 60);

        return [
            'category' => 'queue',
            'severity' => 'critical',
            // The fingerprint strips digits, so this stays one fault however
            // long the queue grows.
            'title' => "The queue has stopped. {$count} ".Str::plural('job', $count)." waiting, the oldest for {$waited} minutes",
            'timestamp' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fullDisk(Carbon $now): ?array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());

        if (! $total || $free === false) {
            return null;
        }

        $used = (int) round((($total - $free) / $total) * 100);

        if ($used <= (int) config('alerts.tech_errors.disk_full_percent', 90)) {
            return null;
        }

        return [
            'category' => 'system',
            'severity' => 'critical',
            'title' => "The server disk is {$used}% full",
            'timestamp' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function isNoise(array $error): bool
    {
        $text = implode(' ', array_filter([
            $error['title'] ?? null,
            $error['raw'] ?? null,
            $error['description'] ?? null,
        ]));

        if (preg_match('/SMS API Error|Payment required on account|HubtelSmsService|Hubtel SMS|SMS notification failed|An SMS failed to send/i', $text)) {
            return true;
        }

        if (str_contains($text, 'Psy\\')) {
            return true;
        }

        return str_contains($text, 'Connection refused') && $error['seen_at']->hour === 6;
    }

    /**
     * Whoever can read system health, with a phone, and still working here.
     *
     * Keyed on the permission rather than the tech_admin role, the same as the
     * SMS health alert, so both reach the same people.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        try {
            return User::permission('view_system_health')
                ->with('employee')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get()
                ->filter(fn (User $u) => $u->employee === null || $u->employee->status === EmployeeStatus::Active)
                ->values();
        } catch (\Throwable $e) {
            Log::warning('Tech error texts could not resolve recipients', ['error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, string $message): int
    {
        $delivered = 0;

        foreach ($recipients as $user) {
            try {
                $user->notifyNow(new TechErrorTextNotification($message));
                $delivered++;
            } catch (\Throwable $e) {
                // Warning, not error: an error would land on the feed and
                // come straight back here as a fault to text.
                Log::warning('Tech error text was not delivered', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $delivered;
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function sendCapNotice(Collection $recipients, int $cap, int $waiting): void
    {
        $message = sprintf(
            'CediBites%s: %d %s today, the most allowed. %d more %s waiting. %s',
            $this->environmentTag(),
            $cap,
            Str::plural('error text', $cap),
            $waiting,
            $waiting === 1 ? 'is' : 'are',
            $this->pageUrl(),
        );

        if ($this->send($recipients, $message) > 0) {
            $this->record(self::EVENT_CAP_REACHED, $message, $recipients->count(), ['waiting' => $waiting]);
        }
    }

    /**
     * Plain ASCII and short enough for one SMS. One curly quote or dot from an
     * explanation switches the whole message to Unicode, which fits 70
     * characters to a text instead of 160.
     */
    private function title(string $title): string
    {
        $clean = rtrim((string) Str::of(Str::ascii($title))->squish(), '.');
        $short = Str::limit($clean, 70, '...');

        // A cut title already ends in its own dots; a full stop after them
        // would read as a typing mistake.
        return str_ends_with($short, '...') ? $short : "{$short}.";
    }

    /** Ghana is UTC+0 all year and `app.timezone` is UTC, so this is local time. */
    private function clock(Carbon $at): string
    {
        return $at->format('g:i a');
    }

    private function environmentTag(): string
    {
        return app()->environment('production') ? '' : ' ('.app()->environment().')';
    }

    private function pageUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/admin/platform/errors';
    }

    private function lastText(string $fingerprint): ?ActivityLog
    {
        return ActivityLog::query()
            ->where('log_name', self::LOG_NAME)
            ->where('event', self::EVENT_TEXTED)
            ->where('properties->fingerprint', $fingerprint)
            ->latest('id')
            ->first();
    }

    private function lastLogged(string $event): ?ActivityLog
    {
        return ActivityLog::query()
            ->where('log_name', self::LOG_NAME)
            ->where('event', $event)
            ->latest('id')
            ->first();
    }

    private function loggedSince(string $event, Carbon $since): bool
    {
        return ActivityLog::query()
            ->where('log_name', self::LOG_NAME)
            ->where('event', $event)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /** Fault texts and round-ups both count towards the day's limit. */
    private function textsSince(Carbon $since): int
    {
        return ActivityLog::query()
            ->where('log_name', self::LOG_NAME)
            ->whereIn('event', [self::EVENT_TEXTED, self::EVENT_SIGN_IN_ROUNDUP])
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $event, string $message, int $recipients, array $properties): void
    {
        activity(self::LOG_NAME)
            ->event($event)
            ->withProperties($properties + ['message' => $message, 'recipients' => $recipients])
            ->log(match ($event) {
                self::EVENT_SIGN_IN_ROUNDUP => 'Failed sign-ins texted to the tech admin',
                self::EVENT_CAP_REACHED => 'Daily error text limit reached',
                default => 'Error texted to the tech admin',
            });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function outcome(array $item, string $outcome): array
    {
        return [
            'title' => $item['title'],
            'severity' => $item['severity'],
            'outcome' => $outcome,
            'message' => $item['message'] ?? null,
        ];
    }
}
