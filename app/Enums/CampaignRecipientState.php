<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * How far one recipient of a campaign got.
 *
 * The split that matters is between Refused and Unsure. Both mean the campaign
 * did not record an acceptance, and they call for opposite responses. A refusal
 * is Hubtel saying no before it took anything, so sending again cannot text
 * anybody twice. Unsure is a request that left and never got a clear answer, so
 * the message may be on a handset already.
 */
enum CampaignRecipientState: string
{
    use HasEnumHelpers;

    /** Waiting for its chunk, or held while the campaign is paused. */
    case Queued = 'queued';

    /** The request is in flight. A row left here means the job died mid-send. */
    case Sending = 'sending';

    /** Hubtel took it. What happened next is in `campaign_deliveries`. */
    case Accepted = 'accepted';

    /** Hubtel said no and took nothing. Safe to send again. */
    case Refused = 'refused';

    /** Sent, with no clear answer back. May have arrived. */
    case Unsure = 'unsure';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Not sent yet',
            self::Sending => 'Sending',
            self::Accepted => 'Accepted by Hubtel',
            self::Refused => 'Refused by Hubtel',
            self::Unsure => 'No answer from Hubtel',
        };
    }
}
