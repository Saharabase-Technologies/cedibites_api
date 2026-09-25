<?php

namespace App\Services\Alerts;

use App\Channels\SmsChannel;
use App\Notifications\AdminAlertNotification;
use App\Services\Platform\RuntimeSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Illuminate\Support\defer;

/**
 * Texts head office about something waiting on them.
 *
 * The numbers come from a list kept in the platform settings panel, not from
 * anyone's account. Head office chose that deliberately: it keeps alerting
 * separate from who can sign in, and a number can be added or dropped without
 * touching an account or a deploy.
 *
 * Every alert is written to the activity log whether or not it could be sent,
 * so "were we told?" always has an answer.
 */
class AdminAlerter
{
    public const LOG_NAME = 'alerts';

    public function __construct(
        private readonly RuntimeSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $context  kept on the activity log entry
     */
    public function send(string $event, string $message, array $context = []): void
    {
        $message = $this->plain($message);
        $phones = $this->phones();

        activity(self::LOG_NAME)
            ->event($event)
            ->withProperties($context + ['message' => $message, 'recipients' => count($phones)])
            ->log(Str::limit($message, 180));

        if ($phones === []) {
            Log::warning('Admin alert has nobody to text. Add numbers under Alerts in the platform settings.', [
                'event' => $event,
            ]);

            return;
        }

        // Inside a request, send once the response has gone, so the manager's
        // tap is not held up by the SMS provider. From the scheduler or a test,
        // send now.
        if (app()->runningInConsole()) {
            $this->deliver($phones, $message, $event);
        } else {
            defer(fn () => $this->deliver($phones, $message, $event));
        }
    }

    /**
     * The alert numbers, in the form the SMS provider wants.
     *
     * @return list<string>
     */
    public function phones(): array
    {
        $raw = (string) $this->settings->get('alerts.admin_phones');

        return self::parsePhones($raw);
    }

    /**
     * @return list<string>
     */
    public static function parsePhones(string $raw): array
    {
        return collect(self::entries($raw))
            ->map(fn ($p) => self::normalise($p))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The numbers as typed, one per entry. Split on commas, semicolons and
     * new lines only: "+233 50 392 3322" is one number written with spaces.
     *
     * @return list<string>
     */
    public static function entries(string $raw): array
    {
        return collect(preg_split('/[,;\r\n]+/', $raw) ?: [])
            ->map(fn ($p) => trim((string) $p))
            ->filter(fn ($p) => $p !== '')
            ->values()
            ->all();
    }

    /** "0503923322", "+233 50 392 3322" and "233503923322" are the same phone. */
    public static function normalise(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '233'.substr($digits, 1);
        }

        return preg_match('/^233[2-9]\d{8}$/', $digits) ? '+'.$digits : null;
    }

    /**
     * @param  list<string>  $phones
     */
    private function deliver(array $phones, string $message, string $event): void
    {
        foreach ($phones as $phone) {
            try {
                Notification::route(SmsChannel::class, $phone)->notifyNow(new AdminAlertNotification($message));
            } catch (\Throwable $e) {
                // Warning, not error: an error would land on the error feed and
                // text the tech admin about a text that did not go.
                Log::warning('Admin alert was not delivered', [
                    'event' => $event,
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Plain ASCII. One curly quote or dash turns a whole SMS into Unicode,
     * which fits 70 characters to a text instead of 160.
     */
    private function plain(string $message): string
    {
        return (string) Str::of(Str::ascii($message))->squish();
    }
}
