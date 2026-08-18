<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One customer's verdict on one order.
 *
 * Not to be confused with FeedbackReport (the beta bug reporter) or
 * MenuItemRating (per-dish stars). See the migration.
 */
class OrderFeedback extends Model
{
    use HasFactory;

    protected $table = 'order_feedback';

    protected $fillable = [
        'order_id',
        'token',
        'rating_overall',
        'rating_food',
        'rating_service',
        'comment',
        'sent_at',
        'submitted_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'rating_overall' => 'integer',
            'rating_food' => 'integer',
            'rating_service' => 'integer',
        ];
    }

    public static function generateToken(?int $length = null): string
    {
        return Str::random($length ?? (int) config('order_feedback.token_length', 8));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * Whether this form will still take an answer.
     *
     * Submitted counts as closed: the form is not for changing your mind, and
     * allowing a second submission through a forwarded link would let anybody
     * holding the URL overwrite what the customer actually said.
     */
    public function isOpen(): bool
    {
        return ! $this->isExpired() && ! $this->isSubmitted();
    }

    /**
     * The customer-facing URL. A page, not a redirect — the apex already
     * forwards to the app, so this can *be* the form rather than bounce to it.
     */
    public function url(): string
    {
        return rtrim((string) config('short_links.base_url'), '/')."/f/{$this->token}";
    }

    /** The same without the scheme, which is what goes in the SMS. */
    public function smsUrl(): string
    {
        return preg_replace('#^https?://#', '', $this->url());
    }

    /** @param  Builder<OrderFeedback>  $query */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->whereNotNull('submitted_at');
    }
}
