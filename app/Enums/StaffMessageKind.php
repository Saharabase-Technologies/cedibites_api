<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * What a message is, which decides how hard it is allowed to push.
 *
 * Kind and urgency are one field on purpose. A separate severity column invites
 * the combination nobody wants — a "notice" marked critical, which either
 * interrupts (and is therefore a caution, mislabelled) or does not (and the
 * severity was decoration).
 */
enum StaffMessageKind: string
{
    use HasEnumHelpers;

    /** Sits in the bell. Never interrupts anything. */
    case Notice = 'notice';

    /**
     * Interrupts — but only once the person is not mid-task. See the
     * InterruptionGate on the frontend. This is the one that carries a warning
     * about someone's own work.
     */
    case Caution = 'caution';

    /**
     * "What's new" — a walkthrough of changes to the platform, paged rather
     * than read at once.
     *
     * Interrupts, like a caution, because somebody who has not been told the
     * rules changed will go on working to the old ones. Unlike a caution it is
     * not about the person's own conduct, so it keeps interrupting at every
     * sign-in until they have actually been through it, rather than being shown
     * once and forgotten.
     */
    case Release = 'release';

    /** One named person, threaded. Ordinary conversation, top-down. */
    case Direct = 'direct';

    /** The upward one: a staff member raising something with the IT team. */
    case StaffQuery = 'staff_query';

    public function label(): string
    {
        return match ($this) {
            self::Notice => 'Notice',
            self::Caution => 'Caution',
            self::Release => "What's new",
            self::Direct => 'Direct message',
            self::StaffQuery => 'Staff query',
        };
    }

    /** Whether this kind may take over the screen once the till is idle. */
    public function interrupts(): bool
    {
        return $this === self::Caution || $this === self::Release;
    }

    /** Kinds that take over the screen. The inbox's pending set is exactly these. */
    public static function interruptingValues(): array
    {
        return array_values(array_map(
            fn (self $kind) => $kind->value,
            array_filter(self::cases(), fn (self $kind) => $kind->interrupts()),
        ));
    }

    /**
     * Whether this kind is paged through as slides.
     *
     * Only a release carries steps. A caution with five pages would be a caution
     * nobody reads to the end.
     */
    public function hasSteps(): bool
    {
        return $this === self::Release;
    }

    /** The kinds an admin may compose. Staff queries are raised by staff, not sent to them. */
    public static function composableByAdmin(): array
    {
        return [self::Notice->value, self::Caution->value, self::Release->value, self::Direct->value];
    }
}
