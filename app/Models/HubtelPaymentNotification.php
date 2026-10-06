<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post from Hubtel's payment notifications, as it arrived.
 *
 * Nothing reads these yet. They are kept so the shape of Hubtel's post can be
 * learned from real payments before anything on the till is built on it.
 */
class HubtelPaymentNotification extends Model
{
    protected $fillable = [
        'account_number',
        'branch_id',
        'method',
        'ip',
        'payload',
        'raw_body',
        'headers',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
