<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\WastageOrigin;
use App\Enums\Inventory\WastageStatus;
use App\Models\Inventory\Concerns\ScopedToLocations;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wastage extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_wastages';

    protected $fillable = [
        'reference',
        'location_id',
        'disposal_location_id',
        'origin',
        'status',
        'total_value',
        'threshold_amount',
        'requires_approval',
        'requires_return',
        'return_transfer_id',
        'source_type',
        'source_id',
        'notes',
        'recorded_by',
        'recorded_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(WastageLine::class, 'wastage_id');
    }

    /**
     * Evidence, both sides of it — the claimant's photos and the approver's.
     * Oldest first so the argument reads in the order it happened.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(WastagePhoto::class, 'wastage_id')->oldest('id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function disposalLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'disposal_location_id');
    }

    public function returnTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'return_transfer_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Where the write-off is actually posted. Only differs from the originating
     * location when the goods were returned for inspection: the branch's stock
     * already left on the return transfer, so the warehouse is what is written
     * down.
     */
    public function postingLocationId(): int
    {
        return (int) ($this->disposal_location_id ?? $this->location_id);
    }

    protected function casts(): array
    {
        return [
            'status' => WastageStatus::class,
            'origin' => WastageOrigin::class,
            'total_value' => 'float',
            'threshold_amount' => 'float',
            'requires_approval' => 'boolean',
            'requires_return' => 'boolean',
            'recorded_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Visible from either end: the branch that declared the loss and the
     * warehouse that has to inspect and sign for it both need to see the claim.
     *
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['location_id', 'disposal_location_id'];
    }
}
