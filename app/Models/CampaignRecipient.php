<?php

namespace App\Models;

use App\Enums\CampaignRecipientState;
use App\Enums\SmsFailureReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on one campaign's list. Never pruned, see the migration.
 */
class CampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id',
        'phone',
        'state',
        'failure_reason',
        'batch_id',
        'attempts',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => CampaignRecipientState::class,
            'failure_reason' => SmsFailureReason::class,
            'attempts' => 'integer',
            'attempted_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The people a resume would send to.
     *
     * Still queued, or clearly refused by Hubtel. A number refused as invalid
     * is left out for good. People we got no answer about are left out unless
     * asked for, because they may have the message already.
     */
    public function scopeResumable(Builder $query, bool $includeUnsure = false): Builder
    {
        $states = [CampaignRecipientState::Queued->value, CampaignRecipientState::Refused->value];

        if ($includeUnsure) {
            $states[] = CampaignRecipientState::Unsure->value;
        }

        return $query
            ->whereIn('state', $states)
            ->where(fn (Builder $q) => $q->whereNull('failure_reason')
                ->orWhere('failure_reason', '!=', SmsFailureReason::InvalidRecipient->value));
    }
}
