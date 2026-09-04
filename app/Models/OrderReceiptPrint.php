<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single receipt, as it came off the printer.
 *
 * `printed_at` is stamped by the server and never accepted from the caller.
 * The machine holding the printer is the one thing in this transaction whose
 * clock has actually been observed to be wrong.
 */
class OrderReceiptPrint extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'employee_id',
        'user_id',
        'kind',
        'reprint_number',
        'copies',
        'source',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
            'reprint_number' => 'integer',
            'copies' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
