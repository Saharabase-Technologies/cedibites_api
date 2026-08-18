<?php

namespace App\Services\StaffMessaging;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing a rule noticed: what it was about, who is answerable for it, and the
 * values its message may quote.
 *
 * `actorUserId` is nullable and frequently null — an order placed through the
 * website has no staff member behind it. A rule targeting only the actor
 * therefore reaches nobody for those matches, which is correct and is recorded
 * as `no_recipients` rather than passed over in silence.
 */
class RuleMatch
{
    /**
     * @param  array<string, scalar|null>  $mergeData  values the template may quote
     */
    public function __construct(
        public readonly ?Model $subject,
        public readonly ?int $actorUserId,
        public readonly ?int $branchId,
        public readonly array $mergeData = [],
    ) {}

    public function subjectType(): ?string
    {
        return $this->subject ? $this->subject::class : null;
    }

    public function subjectId(): ?int
    {
        return $this->subject?->getKey();
    }

    /**
     * The cooldown key.
     *
     * A spike rule has no subject record, so it falls back to the actor: the
     * thing being rate-limited is "telling this person about their cancellations
     * again", and without the fallback every run would look like a fresh subject
     * and the cooldown would never bite.
     */
    public function cooldownKey(): string
    {
        return $this->subject
            ? $this->subjectType().':'.$this->subjectId()
            : 'actor:'.($this->actorUserId ?? 'none');
    }
}
