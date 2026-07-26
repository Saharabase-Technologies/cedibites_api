<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\DailyClosingStatus;
use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyClosing extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_daily_closings';

    protected $fillable = [
        'location_id',
        'business_date',
        'status',
        'wastage_id',
        'notes',
        'opened_by',
        'completed_by',
        'completed_at',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(DailyClosingLine::class, 'daily_closing_id');
    }

    /**
     * The single classification record raised for this count's reasoned
     * variances. It posts no stock — the count adjustments already did.
     */
    public function wastage(): BelongsTo
    {
        return $this->belongsTo(Wastage::class, 'wastage_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected function casts(): array
    {
        return [
            'status' => DailyClosingStatus::class,
            'business_date' => 'date',
            'completed_at' => 'datetime',
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
