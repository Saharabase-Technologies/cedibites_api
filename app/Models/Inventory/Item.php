<?php

namespace App\Models\Inventory;

use Database\Factories\Inventory\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'inventory_items';

    protected $fillable = [
        'sku',
        'name',
        'description',
        'category_id',
        'base_unit_id',
        'default_supplier_id',
        'storage_type',
        'is_consumable',
        'expiry_tracked',
        'reorder_level',
        'min_threshold',
        'purchase_pack_label',
        'purchase_pack_size',
        'weighted_avg_cost',
        'is_active',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    /** Per-location balance rows; sum their quantity for total stock on hand. */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'item_id');
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_consumable' => 'boolean',
            'expiry_tracked' => 'boolean',
            'is_active' => 'boolean',
            'reorder_level' => 'decimal:3',
            'min_threshold' => 'decimal:3',
            'purchase_pack_size' => 'decimal:3',
            'weighted_avg_cost' => 'decimal:4',
        ];
    }
}
