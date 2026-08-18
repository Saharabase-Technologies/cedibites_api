<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Why a rule considered somebody and did not message them.
 *
 * Every one of these is written to `staff_message_rule_fires`. A rule that
 * matched three hundred orders and sent four is behaving correctly, and there is
 * no way to tell that apart from a broken rule without the reasons written down.
 */
enum StaffMessageSuppression: string
{
    use HasEnumHelpers;

    /** Already nagged this person about this exact subject inside the window. */
    case Cooldown = 'cooldown';

    /** This person has had their fill of automated messages this hour. */
    case RecipientCapped = 'recipient_capped';

    /** The rule itself is switched off. Still evaluated, so it can earn trust. */
    case RuleInactive = 'rule_inactive';

    /** The global kill switch is off. */
    case FeatureOff = 'feature_off';

    /** The audience resolved to nobody — everyone matching was suspended or gone. */
    case NoRecipients = 'no_recipients';

    /**
     * True at match time, no longer true at send time. The order moved while the
     * job sat in the queue. Messaging somebody about work they have already done
     * is the fastest way to make the whole channel ignorable.
     */
    case AlreadyResolved = 'already_resolved';

    /** Another rule with a higher priority already claimed this subject. */
    case LowerPriority = 'lower_priority';

    public function label(): string
    {
        return match ($this) {
            self::Cooldown => 'Already told them recently',
            self::RecipientCapped => 'Too many messages for one person',
            self::RuleInactive => 'Rule is switched off',
            self::FeatureOff => 'Automation is switched off',
            self::NoRecipients => 'Nobody to send to',
            self::AlreadyResolved => 'Sorted itself out before we sent',
            self::LowerPriority => 'A higher-priority rule took it',
        };
    }
}
