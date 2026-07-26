<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\ReconciliationStatus;
use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReconciliationCycle extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_reconciliation_cycles';

    protected $fillable = [
        'location_id',
        'status',
        'wastage_id',
        'notes',
        'net_variance_value',
        'threshold_amount',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ReconciliationLine::class, 'cycle_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * The classification record for this cycle's reasoned variances. It posts no
     * stock - the cycle adjustments already brought the ledger to the count.
     */
    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class, 'wastage_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'net_variance_value' => 'decimal:4',
            'threshold_amount' => 'decimal:4',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['location_id'];
    }
}
