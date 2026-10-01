<?php

namespace App\Enums;

/**
 * Why an SMS send failed, reduced to the handful of causes that call for
 * different responses.
 *
 * The split that matters is systemic vs incidental. A bad phone number fails one
 * message and the next one is fine; no credit, bad credentials or missing config
 * fail every message until a human intervenes, and those are the ones worth
 * waking someone for. isSystemic() is what the alerting keys on.
 */
enum SmsFailureReason: string
{
    case NoCredit = 'no_credit';
    case AuthFailed = 'auth_failed';
    case ConfigMissing = 'config_missing';
    case InvalidRecipient = 'invalid_recipient';
    case RateLimited = 'rate_limited';
    case Connection = 'connection';
    case Unknown = 'unknown';

    /**
     * Map a provider error string onto a reason.
     *
     * Hubtel reports an empty account as "Payment required on account" on the
     * send call itself — there is no balance endpoint in their published API, so
     * the rejected send IS the low-balance signal. Match it first.
     */
    public static function classify(?string $message): self
    {
        $text = mb_strtolower(trim((string) $message));

        if ($text === '') {
            return self::Unknown;
        }

        return match (true) {
            str_contains($text, 'payment required'),
            str_contains($text, 'insufficient'),
            str_contains($text, 'out of credit'),
            str_contains($text, 'low balance') => self::NoCredit,

            str_contains($text, 'not properly configured') => self::ConfigMissing,

            str_contains($text, 'unauthor'),
            str_contains($text, 'forbidden'),
            str_contains($text, 'invalid credential'),
            str_contains($text, 'authentication') => self::AuthFailed,

            str_contains($text, 'invalid phone number'),
            str_contains($text, 'invalid recipient'),
            str_contains($text, 'invalid destination'),
            str_contains($text, 'not routable') => self::InvalidRecipient,

            str_contains($text, 'rate limit'),
            str_contains($text, 'too many requests'),
            str_contains($text, 'throttl') => self::RateLimited,

            str_contains($text, 'failed to connect'),
            str_contains($text, 'timed out'),
            str_contains($text, 'timeout'),
            str_contains($text, 'could not resolve') => self::Connection,

            default => self::Unknown,
        };
    }

    /**
     * What Hubtel's numeric `status` means, in the words classify() reads.
     *
     * The batch endpoint refuses with a bare number and no statusDescription:
     * `{"batchId":null,"status":12,"data":null}`. The single-send endpoint says
     * the same thing in words. Without this table the number fell through to
     * Unknown, which is how 3,500 texts refused for want of credit on
     * 2026-10-01 were filed as "Unrecognised SMS error" and no alert fired.
     *
     * Only the codes in Hubtel's response code table are here. Anything else
     * returns null and the caller keeps the raw body.
     */
    public static function describeHubtelStatus(mixed $status): ?string
    {
        if (! is_numeric($status)) {
            return null;
        }

        return match ((int) $status) {
            1 => 'Invalid destination address',
            4 => 'Message is not routable on the Hubtel gateway',
            12 => 'Payment required on account',
            100 => 'Invalid request',
            default => null,
        };
    }

    /**
     * Will this keep failing every message until someone acts?
     */
    public function isSystemic(): bool
    {
        return in_array($this, [self::NoCredit, self::AuthFailed, self::ConfigMissing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NoCredit => 'SMS account out of credit',
            self::AuthFailed => 'SMS credentials rejected',
            self::ConfigMissing => 'SMS is not configured',
            self::InvalidRecipient => 'Invalid recipient number',
            self::RateLimited => 'Rate limited by the provider',
            self::Connection => 'Cannot reach the SMS provider',
            self::Unknown => 'Unrecognised SMS error',
        };
    }

    /**
     * What the person reading the alert should actually do.
     */
    public function remedy(): string
    {
        return match ($this) {
            self::NoCredit => 'Top up the Hubtel SMS account: hubtel.com → Messaging → Manage → Programmable SMS → Add Funds.',
            self::AuthFailed => 'Check HUBTEL_CLIENT_ID and HUBTEL_CLIENT_SECRET against the Hubtel dashboard.',
            self::ConfigMissing => 'HUBTEL_CLIENT_ID / HUBTEL_CLIENT_SECRET are missing from the environment.',
            self::InvalidRecipient => 'One or more stored phone numbers are malformed — check the affected records.',
            self::RateLimited => 'Sending faster than the account allows. Usually clears on its own.',
            self::Connection => 'Network or provider outage. Usually clears on its own; check again shortly.',
            self::Unknown => 'Check the recent failures on the platform health page for the provider message.',
        };
    }
}
