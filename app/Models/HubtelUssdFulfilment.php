<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One post to our fulfilment URL, as it arrived.
 *
 * Hubtel sends it when a dial has been paid. Kept whole, like the payment
 * notifications, and never trusted: VerifyHubtelUssdFulfilment asks Hubtel's
 * status check and writes what became of it to `outcome`.
 */
class HubtelUssdFulfilment extends Model
{
    protected $fillable = [
        'session_id',
        'ip',
        'payload',
        'raw_body',
        'headers',
        'outcome',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
