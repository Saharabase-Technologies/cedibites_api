<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * total_sales and order_count are what the till's browser sent after each
     * sale, and it skipped every MoMo prompt sale (T12). They are kept only so
     * the screens that read them keep working. The money a shift took comes
     * from its payments: see ShiftMoney.
     */
    protected $fillable = [
        'employee_id',
        'branch_id',
        'login_at',
        'logout_at',
        'total_sales',
        'order_count',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'total_sales' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shiftOrders(): HasMany
    {
        return $this->hasMany(ShiftOrder::class);
    }

    /** The payments taken on this shift, by its own person (D9). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
