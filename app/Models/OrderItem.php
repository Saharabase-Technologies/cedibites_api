<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'menu_item_id',
        'menu_item_option_id',
        'menu_item_snapshot',
        'menu_item_option_snapshot',
        'quantity',
        'unit_price',
        'subtotal',
        'special_instructions',
        // Null for the order as placed; the round for an addition (Phase 4b).
        'order_round_id',
    ];

    protected function casts(): array
    {
        return [
            'menu_item_snapshot' => 'array',
            'menu_item_option_snapshot' => 'array',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(OrderRound::class, 'order_round_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function menuItemOption(): BelongsTo
    {
        return $this->belongsTo(MenuItemOption::class);
    }

    /**
     * Leave out the lines of a round cancelled on its own (D45). They stay on
     * the order as a record, and were never sold. For any item count or item
     * sales figure; $table is the alias the query gives order_items.
     */
    public static function leaveOutCancelledRounds($query, string $table = 'order_items')
    {
        return $query->where(fn ($q) => $q->whereNull("{$table}.order_round_id")
            ->orWhereNotExists(fn ($r) => $r->from('order_rounds')
                ->whereColumn('order_rounds.id', "{$table}.order_round_id")
                ->where('order_rounds.status', 'cancelled')));
    }
}
