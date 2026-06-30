<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeResolution extends Model
{
    protected $table = 'inventory_dispute_resolutions';

    protected $fillable = [
        'transfer_id',
        'status',
        'raised_by',
        'reason',
        'discrepancy_qty',
        'corrective_transfer_id',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }

    public function correctiveTransfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'corrective_transfer_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    protected function casts(): array
    {
        return [
            'discrepancy_qty' => 'decimal:4',
            'resolved_at' => 'datetime',
        ];
    }
}
