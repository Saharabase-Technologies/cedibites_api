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

    /** Per-location overrides of this item's global reorder thresholds. */
    public function locationThresholds(): HasMany
    {
        return $this->hasMany(ItemLocationThreshold::class, 'item_id');
    }

    /**
     * The reorder point and critical minimum that apply at one location: its own
     * override where it has set one, the item's global figure otherwise. Either
     * half falls back independently.
     *
     * @return array{reorder_level: float|null, min_threshold: float|null, is_location_specific: bool}
     */
    public function thresholdsAt(?int $locationId): array
    {
        $global = [
            'reorder_level' => $this->reorder_level !== null ? (float) $this->reorder_level : null,
            'min_threshold' => $this->min_threshold !== null ? (float) $this->min_threshold : null,
            'is_location_specific' => false,
        ];

        if ($locationId === null) {
            return $global;
        }

        $override = $this->relationLoaded('locationThresholds')
            ? $this->locationThresholds->firstWhere('location_id', $locationId)
            : $this->locationThresholds()->where('location_id', $locationId)->first();

        if (! $override) {
            return $global;
        }

        return [
            'reorder_level' => $override->reorder_level !== null
                ? (float) $override->reorder_level
                : $global['reorder_level'],
            'min_threshold' => $override->min_threshold !== null
                ? (float) $override->min_threshold
                : $global['min_threshold'],
            'is_location_specific' => true,
        ];
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
