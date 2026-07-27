<?php

namespace App\Domain\Inventory\Stock;

/**
 * The verdict on whether a branch can make an order.
 *
 * Three states, not two. `judged` separates "we looked and it is fine" from "we
 * could not look" — a branch with no inventory location or a dish with no
 * recipe produces no verdict, and no verdict must never be read as a refusal.
 */
class StockCheckResult
{
    /**
     * @param  list<array{item_id: int, item_name: string, unit: string|null, required: float, available: float}>  $shortfalls
     */
    public function __construct(
        public readonly array $shortfalls = [],
        public readonly bool $judged = true,
        public readonly ?string $reason = null,
    ) {}

    public static function ok(): self
    {
        return new self;
    }

    public static function unjudged(string $reason): self
    {
        return new self(judged: false, reason: $reason);
    }

    /**
     * Whether the sale may proceed. An unjudged check passes — see the note on
     * StockAvailabilityService about never blocking on a configuration gap.
     */
    public function canSell(): bool
    {
        return $this->shortfalls === [];
    }

    /**
     * What to put in front of the cashier. Names the ingredient, because "out of
     * stock" tells them nothing they can act on.
     */
    public function message(): string
    {
        if ($this->canSell()) {
            return $this->judged ? 'In stock.' : ($this->reason ?? 'Stock was not checked.');
        }

        $names = array_map(
            fn (array $s) => $s['item_name'].' (need '.$this->trim($s['required'])
                .$this->unit($s).', have '.$this->trim($s['available']).$this->unit($s).')',
            $this->shortfalls,
        );

        return 'Not enough '.implode('; ', $names).'.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'can_sell' => $this->canSell(),
            'judged' => $this->judged,
            'reason' => $this->reason,
            'shortfalls' => $this->shortfalls,
            'message' => $this->message(),
        ];
    }

    /** @param array{unit: string|null} $shortfall */
    private function unit(array $shortfall): string
    {
        return $shortfall['unit'] ? ' '.$shortfall['unit'] : '';
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
