<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One voucher out of a batch, good for one order.
 *
 * Whether it has been used is read off the orders that carry it, cancelled
 * ones aside, so a cancelled order gives it back without anybody touching it.
 */
class PromoCode extends Model
{
    /** No 0 or O, no 1, I or L: nothing a customer can misread off an SMS. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = ['promo_id', 'code', 'lookup', 'batch', 'created_by'];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** What a code is matched on: capitals, and no spaces or dashes. */
    public static function lookupKey(?string $code): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', (string) $code));
    }

    /** A fresh code in the batch's shape: PREFIX-XXXXXX, or eight characters with no prefix. */
    public static function makeCode(?string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix));
        $length = $prefix === '' ? 8 : 6;
        $body = '';
        for ($i = 0; $i < $length; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $prefix === '' ? $body : Str::limit($prefix, 12, '').'-'.$body;
    }
}
