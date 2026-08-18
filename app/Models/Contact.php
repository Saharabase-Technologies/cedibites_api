<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A number we hold but have not sold to.
 *
 * Read the table comment in the migration before changing anything here: this
 * model is kept out of every customer metric by living in its own table, and the
 * moment somebody adds a `contacts` join to an analytics query that property is
 * gone.
 */
class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'source',
        'contact_import_id',
        'converted_at',
        'converted_order_id',
        'customer_id',
        'was_customer_before_import',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
            'was_customer_before_import' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ContactImport::class, 'contact_import_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Still supplementary — has never bought anything from us.
     *
     * This is the scope the campaign audience uses, and the one that defines
     * what "not counted as a customer" means in practice.
     */
    public function scopeUnconverted(Builder $query): Builder
    {
        return $query->whereNull('converted_at');
    }

    /** Has since ordered, and is therefore counted by the real customer metrics. */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->whereNotNull('converted_at');
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * Converted by an order placed after we imported them — the only case the
     * list can honestly claim credit for.
     *
     * A number that was already ordering before the upload is converted too, but
     * we did not acquire them; we just found a number we already had.
     */
    public function isAcquired(): bool
    {
        return $this->isConverted() && ! $this->was_customer_before_import;
    }

    /** What the list view shows in its status column. */
    public function status(): string
    {
        if (! $this->isConverted()) {
            return 'supplementary';
        }

        return $this->was_customer_before_import ? 'already_customer' : 'acquired';
    }

    /**
     * How long they sat on the list before they first ordered.
     *
     * Null for anybody who was already a customer — the figure would be negative
     * and would read as a list converting people before it was uploaded. This is
     * the number that says whether a list is worth buying again: 4,000 contacts
     * that convert at a median of nine days is a different asset from 4,000 that
     * take eight months.
     */
    public function daysToConvert(): ?int
    {
        if (! $this->isConverted() || $this->was_customer_before_import || ! $this->created_at) {
            return null;
        }

        $days = (int) $this->created_at->diffInDays($this->converted_at, false);

        return $days < 0 ? null : $days;
    }
}
