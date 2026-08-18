<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * What became of one message.
 *
 * "Not delivered" is three different things and they call for three different
 * responses. Collapsing them into one number is what makes a delivery report
 * useless: you cannot tell a dead number from a handset that was switched off,
 * and those need opposite actions.
 *
 * The provider's own wording is stored alongside this, verbatim, on every row.
 * Hubtel's status field is prose rather than an enum and their documentation is
 * gone, so this mapping is built from what has actually been observed. Anything
 * unrecognised lands in Pending and keeps its original text — the one thing we
 * must never do is throw away a status we did not expect and report a guess.
 */
enum DeliveryOutcome: string
{
    use HasEnumHelpers;

    /** A handset lit up. The only one we can be certain about. */
    case Delivered = 'delivered';

    /**
     * The carrier tried and gave up, or refused it outright. Bad number,
     * disconnected, or barred. Permanent — retrying costs money and changes
     * nothing.
     */
    case Failed = 'failed';

    /** Still in flight. Carriers retry for hours; this is not bad news yet. */
    case Pending = 'pending';

    /**
     * We stopped asking before it resolved.
     *
     * NOT a failure, and the distinction matters. The handset was most likely
     * off or out of coverage for the whole validity window — the person is fine
     * and worth trying again later. Some carriers also never return a receipt
     * at all, so a message can arrive and still end up here.
     *
     * Counting these as failures would understate delivery and invite a re-send,
     * which texts everybody twice and bills us twice.
     */
    case Unconfirmed = 'unconfirmed';

    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Pending => 'Still trying',
            self::Unconfirmed => 'Never confirmed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Delivered => 'Reached the handset.',
            self::Failed => 'The network rejected it or gave up. Usually a dead or barred number.',
            self::Pending => 'Accepted and still being retried by the network.',
            self::Unconfirmed => 'No confirmation before we stopped asking. Usually a handset that was off — not a bad number.',
        };
    }

    /** Whether this can still change. */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Read Hubtel's wording into one of ours.
     *
     * Matched case-insensitively on substrings because the field is prose. An
     * unknown status is Pending rather than Failed: guessing "failed" from a
     * word we do not recognise would report a delivery problem that may not
     * exist, and Pending resolves to Unconfirmed on its own once the polling
     * window closes.
     */
    public static function fromProviderStatus(?string $status): self
    {
        $value = strtolower(trim((string) $status));

        if ($value === '') {
            return self::Pending;
        }

        if (str_contains($value, 'deliver') && ! str_contains($value, 'undeliver')) {
            return self::Delivered;
        }

        foreach (['undeliver', 'fail', 'reject', 'invalid', 'blacklist', 'barred', 'unknown subscriber'] as $needle) {
            if (str_contains($value, $needle)) {
                return self::Failed;
            }
        }

        // Expired means the network retried for its whole validity window and
        // never got through — the handset was off, not wrong. That is the
        // definition of unconfirmed, not of failure.
        if (str_contains($value, 'expir')) {
            return self::Unconfirmed;
        }

        return self::Pending;
    }
}
