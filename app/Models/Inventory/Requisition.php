<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\RequisitionStatus;
use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Requisition extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_requisitions';

    protected $fillable = [
        'reference',
        'requesting_location_id',
        'source_type',
        'source_location_id',
        'purpose',
        'status',
        'notes',
        'requested_by',
        'approved_by',
        'fulfilling_transfer_id',
        'rejection_reason',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'fulfilled_at',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class, 'requisition_id');
    }

    public function requestingLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'requesting_location_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function fulfillingTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'fulfilling_transfer_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['requesting_location_id', 'source_location_id'];
    }

    /**
     * Hide other people's drafts.
     *
     * A draft is unfinished thinking, not a request anyone has made yet — it
     * should not appear in someone else's queue or approval list. Everything
     * from `submitted` onward is a record and stays visible to whoever the
     * location scope allows.
     */
    public function scopeVisibleDrafts(Builder $query, ?User $user): Builder
    {
        return $query->where(
            fn (Builder $q) => $q
                ->where('status', '!=', RequisitionStatus::Draft->value)
                ->orWhere('requested_by', $user?->id ?? 0)
        );
    }

    /** Whether an already-loaded requisition is hidden as someone else's draft. */
    public function isHiddenDraftFor(?User $user): bool
    {
        return $this->status === RequisitionStatus::Draft
            && (int) $this->requested_by !== (int) ($user?->id ?? 0);
    }
}
