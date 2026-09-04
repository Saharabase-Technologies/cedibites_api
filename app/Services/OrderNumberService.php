<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    /**
     * Generate a unique order number, retrying inside a transaction if two
     * concurrent requests race to the same value. The orders table must have
     * a unique index on order_number to make this safe.
     *
     * Every read here includes soft-deleted orders. The unique index counts a
     * soft-deleted row, so a generator that cannot see one will hand out a
     * number the insert then rejects. Deleting the newest order is enough to
     * jam the series until somebody notices.
     */
    public function generate(): string
    {
        return DB::transaction(function () {
            $last = $this->lastAlphabeticCode();
            $next = $last ? $this->increment($last) : 'A001';

            while (Order::withTrashed()->lockForUpdate()->where('order_number', $next)->exists()) {
                $next = $this->increment($next);
            }

            return $next;
        });
    }

    private function lastAlphabeticCode(): ?string
    {
        $candidates = Order::withTrashed()
            ->pluck('order_number')
            ->filter(fn (string $n) => (bool) preg_match('/^[A-Z]+\d{3}$/', $n));

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sort(fn (string $a, string $b) => $this->compareCodes($a, $b))
            ->last();
    }

    private function compareCodes(string $a, string $b): int
    {
        $pa = $this->parse($a);
        $pb = $this->parse($b);

        $len = max(strlen($pa['letters']), strlen($pb['letters']));
        $la = str_pad($pa['letters'], $len, ' ', STR_PAD_RIGHT);
        $lb = str_pad($pb['letters'], $len, ' ', STR_PAD_RIGHT);
        $cmp = strcmp($la, $lb);
        if ($cmp !== 0) {
            return $cmp;
        }

        return $pa['num'] <=> $pb['num'];
    }

    /**
     * @return array{letters: string, num: int}
     */
    private function parse(string $code): array
    {
        preg_match('/^([A-Z]+)(\d{3})$/', $code, $m);

        return [
            'letters' => $m[1],
            'num' => (int) $m[2],
        ];
    }

    private function increment(string $code): string
    {
        $p = $this->parse($code);
        if ($p['num'] < 999) {
            return $p['letters'].str_pad((string) ($p['num'] + 1), 3, '0', STR_PAD_LEFT);
        }

        return $this->advancePrefix($p['letters']).'001';
    }

    private function advancePrefix(string $letters): string
    {
        // Single letter A-Y → next letter
        if (strlen($letters) === 1 && $letters < 'Z') {
            return chr(ord($letters) + 1);
        }

        // Single letter Z → start two-letter prefixes
        if ($letters === 'Z') {
            return 'AA';
        }

        // Two letters: increment second letter
        if (strlen($letters) === 2 && $letters[1] < 'Z') {
            return $letters[0].chr(ord($letters[1]) + 1);
        }

        // Two letters ending in Z: increment first letter
        if (strlen($letters) === 2 && $letters[0] < 'Z') {
            return chr(ord($letters[0]) + 1).'A';
        }

        // ZZ999 — truly exhausted (702 prefixes × 999 = ~701k orders)
        throw new \RuntimeException("Order number prefix exhausted: {$letters}");
    }
}
