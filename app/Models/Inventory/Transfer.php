<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\TransferStatus;
use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transfer extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_transfers';

    protected $fillable = [
        'reference',
        'source_location_id',
        'destination_location_id',
        'status',
        'parent_transfer_id',
        'requisition_id',
        'wastage_id',
        'source_validation_overridden_by',
        'notes',
        'created_by',
        'approved_by',
        'sent_by',
        'received_by',
        'rejected_by',
        'cancelled_by',
        'submitted_at',
        'approved_at',
        'sent_at',
        'received_at',
        'rejected_at',
        'cancelled_at',
        'cancel_reason',
        'reject_reason',
        'reject_reason_code',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class, 'transfer_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function parentTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transfer_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class, 'requisition_id');
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(DisputeResolution::class, 'transfer_id')->latestOfMany();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Set when this transfer is the return leg of a wastage claim. */
    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class, 'wastage_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['source_location_id', 'destination_location_id'];
    }
}
