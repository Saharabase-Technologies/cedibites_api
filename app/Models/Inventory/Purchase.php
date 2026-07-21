<?php

namespace App\Models\Inventory;

use App\Models\User;
use App\Models\Inventory\Concerns\ScopedToLocations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    use ScopedToLocations;

    protected $table = 'inventory_purchases';

    protected $fillable = [
        'reference',
        'purchase_order_id',
        'supplier_id',
        'supplier_name',
        'destination_location_id',
        'is_urgent_buy',
        'urgent_buy_reason',
        'invoice_number',
        'notes',
        'recorded_by',
        'total_paid',
        'received_at',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'is_urgent_buy' => 'boolean',
            'total_paid' => 'decimal:4',
            'received_at' => 'datetime',
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
