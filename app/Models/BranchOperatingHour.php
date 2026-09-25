<?php

namespace App\Models;

use App\Domain\Inventory\Closing\DailyClosingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchOperatingHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'day_of_week',
        'is_open',
        'open_time',
        'close_time',
        'manual_override_open',
        'manual_override_at',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'manual_override_open' => 'boolean',
            'manual_override_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether somebody's hand-set open or close still applies.
     *
     * An override lasts for the business day it was set on and no longer. It
     * is stored on the weekday's row, and it used to be read with no date at
     * all, so closing Ashaiman by hand one Sunday closed it every Sunday after,
     * and opening East Legon by hand one Sunday kept it open around the clock
     * every Sunday. Four of those were live on prod in September 2026.
     *
     * The business day is the IMS one, which ends at 03:00, not midnight. A
     * branch closed by hand at 01:00 after late trading is closing the day it
     * worked, and must open as normal at 10:00.
     */
    public function overrideInForce(): bool
    {
        if ($this->manual_override_open === null || $this->manual_override_at === null) {
            return false;
        }

        return DailyClosingService::currentBusinessDate($this->manual_override_at)
            === DailyClosingService::currentBusinessDate();
    }

    /**
     * Determine if this day is currently open based on schedule and manual overrides.
     */
    public function isCurrentlyOpen(): bool
    {
        if ($this->overrideInForce()) {
            return $this->manual_override_open;
        }

        // If not scheduled to be open today, return false
        if (! $this->is_open) {
            return false;
        }

        // Check if current time is within operating hours
        $currentTime = now()->format('H:i:s');

        return $currentTime >= $this->open_time && $currentTime <= $this->close_time;
    }
}
