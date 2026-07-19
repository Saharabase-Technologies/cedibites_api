<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosingLine extends Model
{
    protected $table = 'inventory_daily_closing_lines';

    protected $fillable = [
        'daily_closing_id',
        'item_id',
        'unit_id',
        'expected_qty',
        'counted_qty',
        'variance',
    ];

    public function dailyClosing(): BelongsTo
    {
        return $this->belongsTo(DailyClosing::class, 'daily_closing_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'variance' => 'decimal:4',
        ];
    }
}
