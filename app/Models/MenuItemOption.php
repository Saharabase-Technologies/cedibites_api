<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MenuItemOption extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\MenuItemOptionFactory> */
    use HasFactory, InteractsWithMedia, SoftDeletes;

    protected $fillable = [
        'menu_item_id',
        'option_key',
        'option_label',
        'display_name',
        'price',
        'display_order',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            // Bolt's own price and promo, set on the menu's Bolt page. Kept out
            // of $fillable: only BoltController writes them.
            'bolt_price' => 'decimal:2',
            'bolt_promo_percent' => 'decimal:2',
            'display_order' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(MenuItemOptionBranchPrice::class);
    }

    public function branchPrice(): HasOne
    {
        return $this->hasOne(MenuItemOptionBranchPrice::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('menu-item-options')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(400)
            ->height(300)
            ->sharpen(10)
            ->nonQueued();
    }
}
