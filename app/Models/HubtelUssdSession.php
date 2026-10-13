<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dial of a branch code, from the first post to the payment.
 *
 * Written only by UssdPayments. The branch comes from the code the customer
 * dialled, which is the whole reason the service exists: Hubtel's own
 * notification never says which branch a payment was for.
 */
class HubtelUssdSession extends Model
{
    public const ASKED_BRANCH = 'asked_branch';

    public const ASKED_AMOUNT = 'asked_amount';

    /** Handed to Hubtel with the amount. The customer is at the PIN. */
    public const SENT_TO_PAY = 'sent_to_pay';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    /** We ended it: no branch to pay, or no usable amount after three tries. */
    public const REFUSED = 'refused';

    /** States in which a screen of ours is still waiting on the customer. */
    public const WAITING = [self::ASKED_BRANCH, self::ASKED_AMOUNT];

    /** Posts and answers kept per session. A dial is two or three; this stops a loop filling the row. */
    private const TRAIL_LIMIT = 12;

    protected $fillable = [
        'session_id',
        'branch_id',
        'mobile',
        'operator',
        'platform',
        'service_code',
        'dialled',
        'amount',
        'amount_from',
        'state',
        'bad_tries',
        'hubtel_order_id',
        'acked_at',
        'ack_note',
        'trail',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'bad_tries' => 'integer',
            'acked_at' => 'datetime',
            'trail' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Add one post from Hubtel and our answer to the trail.
     *
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $answer
     */
    public function remember(array $post, array $answer): void
    {
        $trail = $this->trail ?? [];
        $trail[] = ['at' => now()->toIso8601String(), 'in' => $post, 'out' => $answer];

        $this->trail = array_slice($trail, -self::TRAIL_LIMIT);
    }
}
