<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operational inventory alert. Deduplicated while open: there is at most one
 * open alert per (type, item, location), updated in place as the condition
 * worsens and resolved when it clears.
 */
class Alert extends Model
{
    protected $table = 'inventory_alerts';

    protected $fillable = [
        'type',
        'severity',
        'status',
        'item_id',
        'location_id',
        'reference_type',
        'reference_id',
        'message',
        'context',
        'resolved_by',
        'resolved_at',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Raise (or refresh) the open negative-stock alert for an item at a location.
     * A sale drove the balance below zero — the order already completed, so this
     * is a signal to reconcile, not a blocker. Deduped on (type, item, location,
     * open) so repeated overdraws update one alert rather than spamming new rows.
     */
    public static function raiseNegativeStock(int $itemId, int $locationId, float $balance, ?int $orderId = null): self
    {
        return static::updateOrCreate(
            [
                'type' => 'negative_stock',
                'item_id' => $itemId,
                'location_id' => $locationId,
                'status' => 'open',
            ],
            [
                'severity' => 'critical',
                'message' => 'Sales have outrun recorded stock — balance is now '.rtrim(rtrim(number_format($balance, 4, '.', ''), '0'), '.').'. Receive or produce to reconcile.',
                'reference_type' => $orderId !== null ? 'order' : null,
                'reference_id' => $orderId,
                'context' => ['balance' => $balance, 'order_id' => $orderId],
            ],
        );
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
