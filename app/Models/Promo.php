<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Promo extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    /** Applies by itself to every order that qualifies. */
    public const AUTOMATIC = 'automatic';

    /** One code, `code`, typed by anybody who has it. */
    public const SHARED_CODE = 'shared_code';

    /** A batch of codes in `promo_codes`, each good for one order. */
    public const SINGLE_USE = 'single_use';

    public const REDEMPTIONS = [self::AUTOMATIC, self::SHARED_CODE, self::SINGLE_USE];

    /**
     * A promo saved with a code and no word on how it applies is a shared-code
     * promo. The column's default is `automatic`, so without this a seeder, a
     * test or a tinker session writing `code` alone would make a promo meant
     * for one flyer apply to every order.
     */
    protected static function booted(): void
    {
        static::saving(function (Promo $promo) {
            if ($promo->redemption === null) {
                $promo->redemption = $promo->code !== null ? self::SHARED_CODE : self::AUTOMATIC;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin')
            ->logOnly(['name', 'code', 'redemption', 'type', 'value', 'is_active', 'start_date', 'end_date', 'max_uses', 'max_uses_per_customer', 'first_order_only'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'code',
        'redemption',
        'type',
        'value',
        'scope',
        'applies_to',
        'min_order_value',
        'max_order_value',
        'max_discount',
        'max_uses',
        'max_uses_per_customer',
        'first_order_only',
        'start_date',
        'end_date',
        'is_active',
        'accounting_code',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'max_order_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'first_order_only' => 'boolean',
        ];
    }

    /**
     * Codes are kept in capitals with no spaces, so "cedi20 " typed at a till
     * and "CEDI20" printed on a flyer are the same code. An empty box means no
     * code, which means the promo applies by itself.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normaliseCode($value),
        );
    }

    public static function normaliseCode(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', (string) $value));

        return $value === '' ? null : $value;
    }

    /** A promo nobody has to type anything for. */
    public function isAutomatic(): bool
    {
        return $this->redemption === self::AUTOMATIC;
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'promo_branches');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'promo_menu_items');
    }
}
