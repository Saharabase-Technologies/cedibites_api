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
        // Clock out and the manager's confirm (staff portal D29, D30). Written
        // only by ShiftClockOut, never from a request's fields.
        'clocked_out_at',
        'clock_out_money',
        'clock_out_owing',
        'clock_out_note',
        'manager_confirmed_by',
        'manager_confirmed_at',
        'manager_note',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'total_sales' => 'decimal:2',
            'clocked_out_at' => 'datetime',
            'clock_out_money' => 'array',
            'clock_out_owing' => 'array',
            'manager_confirmed_at' => 'datetime',
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

    /** The manager who confirmed the shift after its Clock out (D30). */
    public function managerConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_confirmed_by');
    }

    /** The payments taken on this shift, by its own person (D9). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
