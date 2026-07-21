<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\PurchaseOrderStatus;
use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use ScopedToLocations, SoftDeletes;

    protected $table = 'inventory_purchase_orders';

    protected $fillable = [
        'reference',
        'verification_code',
        'supplier_id',
        'destination_location_id',
        'status',
        'requires_approval',
        'estimated_total',
        'actual_total',
        'expected_delivery_date',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'purchase_order_id');
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'requires_approval' => 'boolean',
            'estimated_total' => 'decimal:4',
            'actual_total' => 'decimal:4',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function locationScopeColumns(): array
    {
        return ['destination_location_id'];
    }
}
