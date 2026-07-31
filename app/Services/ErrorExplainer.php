<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a raw exception line into plain English: what happened, why, and what to
 * do about it.
 *
 * Two tiers, in this order:
 *
 *  1. A hand-written table of the errors this system actually produces. These
 *     are exact, instant, free, and stable. A model asked to explain "Route
 *     [login] not defined" will produce something plausible about routing and
 *     miss that it is really an expired token in an API-only app — the specific
 *     thing an operator needs to know. Known errors must never reach the model.
 *
 *  2. The model, for anything unrecognised, reusing the Groq key already
 *     configured for feedback voice-note transcription. Cached by error
 *     signature so a message repeating every twenty minutes costs one call, not
 *     seventy a day.
 *
 * Never throws and never blocks the feed: any failure falls back to the raw
 * message. An error dashboard that breaks on errors is worse than no dashboard.
 */
class ErrorExplainer
{
    /** Explanations are stable per signature; a week is plenty. */
    private const CACHE_TTL_DAYS = 7;

    /**
     * Known errors, matched in order. Keep the most specific patterns first.
     *
     * @var array<int, array{match: array<int, string>, category: string, title: string, cause: string, fix: string, severity?: string}>
     */
    private const KNOWN = [
        [
            'match' => ['Route [login] not defined'],
            'category' => 'authentication',
            'title' => 'Someone was signed out and the API answered with the wrong error',
            'cause' => 'A request arrived with an expired or missing login token. The API tried to redirect the visitor to a sign-in page, but this backend has no such page — it only answers apps — so the redirect failed and it reported a server error instead of simply saying "please sign in again".',
            'fix' => 'Nothing for you to do — this is normal session expiry showing up as the wrong error. The handler in bootstrap/app.php now returns a proper "please sign in again" response, so it should stop appearing. If it continues after the next deploy, a client is calling the API without an Accept: application/json header.',
        ],
        [
            'match' => ['Payment required on account'],
            'category' => 'integrations',
            'title' => 'The SMS account has run out of credit',
            'cause' => 'Hubtel rejected the message because the SMS account balance is empty. Every text — order updates, password reset codes, new staff accounts — stops going out until it is topped up. Customers and staff are not told; the messages simply never arrive.',
            'fix' => 'Top up the SMS account: sign in at hubtel.com, go to Messaging → Manage → Programmable SMS, open the API key and choose Add Funds. Delivery resumes on its own within a minute of the balance clearing.',
        ],
        [
            'match' => ['Invalid phone number format'],
            'category' => 'integrations',
            'title' => 'A saved phone number is not a valid Ghana number',
            'cause' => 'The SMS gateway only accepts Ghana numbers in the form 233XXXXXXXXX. A record was saved with a number it cannot use — usually a typo, a missing digit, or an international number — so no message can be sent to that person.',
            'fix' => 'Open the customer or staff record named in the message and correct the phone number to a valid Ghana mobile (024…, 054…, 059… or the +233 equivalent). This only affects the one record; everyone else still receives messages.',
        ],
        [
            'match' => ['SQLSTATE[08006]', 'Connection refused', 'could not connect to server'],
            'category' => 'database',
            'title' => 'The database was briefly unreachable',
            'cause' => 'The app could not open a connection to the database for a moment. On this server that is almost always the nightly automatic security update restarting PostgreSQL, which happens between 6am and 7am and lasts about a second.',
            'fix' => 'No action if it was a single burst around 6–7am — requests in flight at that instant failed and everything since has been fine. If it repeats outside that window, or continues for more than a minute, the database itself needs looking at.',
        ],
        [
            'match' => ['SQLSTATE'],
            'category' => 'database',
            'title' => 'A database operation failed',
            'cause' => 'A query was rejected by the database. This is usually a bug in the query or a schema change that has not been applied, rather than anything an operator did.',
            'fix' => 'Note the time and what was being done, then send it to a developer — the raw message below identifies the exact query.',
        ],
        [
            'match' => ['Too Many Attempts', 'ThrottleRequests'],
            'category' => 'security',
            'title' => 'Someone is making too many requests',
            'cause' => 'A single client hit the same endpoint far more often than a person could, so the rate limiter began refusing it. That is usually a stuck retry loop in the app, but it can be someone guessing passwords.',
            'fix' => 'If it is one staff member, ask them to close and reopen the app. If the requests are sign-in attempts from an address you do not recognise, treat it as a break-in attempt and reset that account\'s password.',
        ],
        [
            'match' => ['MethodNotAllowedHttpException'],
            'category' => 'system',
            'title' => 'The app called the server the wrong way',
            'cause' => 'A screen sent a request using the wrong method — a read where a write was expected, or the reverse. This is a bug in the app, not something a user did wrong.',
            'fix' => 'Note which screen was open and send it to a developer. Users cannot cause or avoid this one.',
        ],
        [
            'match' => ['ModelNotFoundException', 'No query results for model'],
            'category' => 'system',
            'title' => 'A record vanished before the system finished with it',
            'cause' => 'A background task went looking for something — usually an order — that had already been deleted by the time it ran. The task then failed rather than stopping quietly.',
            'fix' => 'Harmless if the record was deleted on purpose; the customer simply did not get that one notification. If it happens repeatedly, orders are being deleted rather than cancelled, which also removes them from revenue.',
        ],
    ];

    /**
     * @return array{title: string, cause: string, fix: string, category: string, source: string}
     */
    public function explain(string $message, ?string $category = null): array
    {
        foreach (self::KNOWN as $entry) {
            foreach ($entry['match'] as $needle) {
                if (str_contains($message, $needle)) {
                    return [
                        'title' => $entry['title'],
                        'cause' => $entry['cause'],
                        'fix' => $entry['fix'],
                        'category' => $entry['category'],
                        'source' => 'known',
                    ];
                }
            }
        }

        return $this->askModel($message, $category);
    }

    /**
     * @return array{title: string, cause: string, fix: string, category: string, source: string}
     */
    private function askModel(string $message, ?string $category): array
    {
        $fallback = [
            'title' => 'Unrecognised application error',
            'cause' => 'The system hit a problem that has not been seen before, so there is no plain-English explanation for it yet.',
            'fix' => 'Send the technical detail below to a developer along with the time it happened.',
            'category' => $category ?? 'system',
            'source' => 'fallback',
        ];

        if (! $this->enabled()) {
            return $fallback;
        }

        // Signature, not the message: numbers, ids and paths vary between
        // otherwise identical errors and would each buy their own cache entry.
        $signature = md5(preg_replace('/\d+/', 'N', mb_substr($message, 0, 300)) ?? $message);

        return Cache::remember(
            "error-explanation:{$signature}",
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->request($message, $category) ?? $fallback,
        );
    }

    /**
     * @return array{title: string, cause: string, fix: string, category: string, source: string}|null
     */
    private function request(string $message, ?string $category): ?array
    {
        try {
            $response = Http::withToken((string) config('services.error_explainer.key'))
                ->timeout(12)
                ->post(rtrim((string) config('services.error_explainer.base_url'), '/').'/chat/completions', [
                    'model' => config('services.error_explainer.model'),
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => "Technical error from a Ghanaian food-delivery platform:\n\n".mb_substr($message, 0, 1500)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Error explainer non-2xx', ['status' => $response->status()]);

                return null;
            }

            $decoded = json_decode((string) $response->json('choices.0.message.content'), true);

            if (! is_array($decoded) || ! isset($decoded['title'], $decoded['cause'], $decoded['fix'])) {
                return null;
            }

            return [
                'title' => mb_substr((string) $decoded['title'], 0, 120),
                'cause' => mb_substr((string) $decoded['cause'], 0, 600),
                'fix' => mb_substr((string) $decoded['fix'], 0, 600),
                'category' => $category ?? 'system',
                'source' => 'ai',
            ];
        } catch (Throwable $e) {
            Log::warning('Error explainer failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You explain software errors to restaurant managers who are not technical.
        They run branches, take orders and manage staff. They cannot read code and
        cannot deploy anything.

        Reply with JSON only: {"title": "...", "cause": "...", "fix": "..."}

        title: under 12 words, plain language, describes the real-world effect —
          not the exception name. Never include class names, file paths or code.
        cause: 2-3 sentences. What went wrong and what it means for the business
          (lost orders? undelivered messages? a staff member locked out?).
        fix: 2-3 sentences of concrete next steps THIS reader can take — a
          setting to change, an account to top up, a record to correct, someone
          to call. If only a developer can fix it, say so plainly and say what to
          send them. Never invent menu paths or buttons you are not sure exist.

        Be honest about uncertainty. Do not guess at causes you cannot support
        from the error text. Never suggest running commands or editing files.
        PROMPT;
    }

    private function enabled(): bool
    {
        return (bool) config('services.error_explainer.key')
            && (bool) config('services.error_explainer.model');
    }
}
