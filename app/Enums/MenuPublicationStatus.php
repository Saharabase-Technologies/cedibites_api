<?php

namespace App\Enums;

use App\Enums\Concerns\HasEnumHelpers;

/**
 * Where one press of "Put new prices live" stands.
 */
enum MenuPublicationStatus: string
{
    use HasEnumHelpers;

    /** Waiting for its time. Carries whatever is waiting when the time comes. */
    case Scheduled = 'scheduled';

    /** The changes went live. */
    case Published = 'published';

    /** A scheduled publish called off, or one that found nothing waiting. */
    case Cancelled = 'cancelled';

    /** Went live, then put back. */
    case Undone = 'undone';
}
