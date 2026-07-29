<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Customer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('auth')
            ->logOnly(['user_id', 'is_guest', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'user_id',
        'is_guest',
        'guest_session_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_guest' => 'boolean',
            'status' => CustomerStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Every order this customer placed, newest first, for reading back the names
     * they gave at the counter.
     *
     * A separate relation from `orders()` on purpose: the customer list loads
     * `orders` capped at one row for the "last order" column, so the alias list
     * has to declare its own load or it would silently read from that cap. Kept
     * out of the list endpoint entirely — it is detail-page material, and
     * eager-loading every order for every row of a paginated table is not.
     */
    public function orderAliases(): HasMany
    {
        return $this->hasMany(Order::class)
            ->whereNotNull('contact_name')
            ->latest();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
