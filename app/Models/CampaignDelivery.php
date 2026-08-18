<?php

namespace App\Models;

use App\Enums\DeliveryOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What became of one campaign message. See the migration for why this is not
 * `sms_delivery_attempts`.
 */
class CampaignDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'phone',
        'outcome',
        'provider_status',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => DeliveryOutcome::class,
            'rate' => 'decimal:4',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** Still in flight — the only outcome that can still change. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('outcome', DeliveryOutcome::Pending->value);
    }

    /**
     * Everything that did not arrive.
     *
     * Includes Unconfirmed, which is deliberately not the same as Failed — the
     * point of the list is to tell those two apart, not to merge them again.
     */
    public function scopeNotDelivered(Builder $query): Builder
    {
        return $query->where('outcome', '!=', DeliveryOutcome::Delivered->value);
    }
}
