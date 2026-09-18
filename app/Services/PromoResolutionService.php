<?php

namespace App\Services;

use App\Helpers\PhoneHelper;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Promo;
use App\Support\Promos\PromoCodeRefused;
use App\Support\Promos\PromoOffer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which discount an order gets.
 *
 * Two kinds of promo. One without a code applies by itself to every order that
 * qualifies, as promos always have. One with a code applies only when somebody
 * types it, at checkout or at the till. An order carries one discount, and when
 * both kinds qualify the bigger one wins, so typing a code can never cost the
 * customer money.
 *
 * A basket is passed as lines: `menu_item_id` and the line's `amount`. The
 * amounts matter for a promo on particular dishes, which takes its percentage
 * off those dishes and not off the whole order.
 */
class PromoResolutionService
{
    /**
     * The best promo that applies by itself.
     *
     * `$lines` is optional for the sake of clients built before it existed,
     * which send item ids and a subtotal. Without amounts a dish promo falls
     * back to the subtotal, which is what those clients always showed.
     *
     * @param  array<int, int|string>  $itemIds
     * @param  array<int, array{menu_item_id: int|string, amount?: float|int|string|null}>  $lines
     */
    public function resolve(
        array $itemIds,
        string $branchId,
        ?float $subtotal = 0,
        array $lines = [],
        ?string $phone = null,
        ?int $customerId = null,
    ): ?Promo {
        $subtotal = (float) $subtotal;
        $lines = $this->lines($lines, $itemIds);
        $itemIds = array_column($lines, 'menu_item_id');
        $today = Carbon::today()->toDateString();
        $branchIdInt = (int) $branchId;

        $candidates = Promo::query()
            ->with(['branches', 'menuItems'])
            ->whereNull('code')
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->where(function ($q) use ($branchIdInt) {
                $q->where('scope', 'global')
                    ->orWhere(function ($branchQ) use ($branchIdInt) {
                        $branchQ->where('scope', 'branch')
                            ->where(function ($b) use ($branchIdInt) {
                                $b->whereDoesntHave('branches')
                                    ->orWhereHas('branches', fn ($q) => $q->where('branches.id', $branchIdInt));
                            });
                    });
            })
            ->where(function ($q) use ($itemIds) {
                $q->where('applies_to', 'order')
                    ->orWhere(function ($itemsQ) use ($itemIds) {
                        $itemsQ->where('applies_to', 'items')
                            ->where(function ($m) use ($itemIds) {
                                $m->whereDoesntHave('menuItems')
                                    ->orWhereHas('menuItems', fn ($q) => $q->whereIn('menu_items.id', $itemIds));
                            });
                    });
            })
            ->get();

        $phone = $this->knownPhone($phone);
        $best = null;
        $bestDiscount = 0.0;

        foreach ($candidates as $promo) {
            if ($this->refusal($promo, $branchIdInt, $subtotal, $lines, $phone, $customerId) !== null) {
                continue;
            }

            $discount = $this->calculateDiscount($promo, $subtotal, $lines);
            if ($discount > $bestDiscount) {
                $bestDiscount = $discount;
                $best = $promo;
            }
        }

        return $best;
    }

    /**
     * The promo behind a code, or the reason this order cannot have it.
     *
     * @param  array<int, array{menu_item_id: int|string, amount?: float|int|string|null}>  $lines
     *
     * @throws PromoCodeRefused
     */
    public function resolveCode(
        string $code,
        string $branchId,
        float $subtotal,
        array $lines,
        ?string $phone = null,
        ?int $customerId = null,
    ): Promo {
        $code = (string) Promo::normaliseCode($code);
        $promo = $code === ''
            ? null
            : Promo::query()->with(['branches', 'menuItems'])->where('code', $code)->first();

        if (! $promo) {
            throw new PromoCodeRefused('not_found', "There is no code {$code}. Check the spelling.");
        }

        $lines = $this->lines($lines, []);
        $reason = $this->refusal($promo, (int) $branchId, $subtotal, $lines, $this->knownPhone($phone), $customerId);

        if ($reason !== null) {
            throw new PromoCodeRefused($reason, $this->explain($reason, $promo, (int) $branchId));
        }

        return $promo;
    }

    /**
     * The one discount this order carries.
     *
     * @param  array<int, array{menu_item_id: int|string, amount?: float|int|string|null}>  $lines
     *
     * @throws PromoCodeRefused when a code was given and cannot be used
     */
    public function bestOffer(
        string $branchId,
        float $subtotal,
        array $lines,
        ?string $code = null,
        ?string $phone = null,
        ?int $customerId = null,
    ): PromoOffer {
        $automatic = $this->resolve([], $branchId, $subtotal, $lines, $phone, $customerId);
        $automaticDiscount = $automatic ? $this->calculateDiscount($automatic, $subtotal, $lines) : 0.0;

        if (Promo::normaliseCode($code) === null) {
            return $automatic ? new PromoOffer($automatic, $automaticDiscount) : PromoOffer::none();
        }

        $coded = $this->resolveCode((string) $code, $branchId, $subtotal, $lines, $phone, $customerId);
        $codedDiscount = $this->calculateDiscount($coded, $subtotal, $lines);

        // A tie goes to the code: the customer asked for it, and the receipt
        // should name what they asked for.
        if ($codedDiscount >= $automaticDiscount) {
            return new PromoOffer($coded, $codedDiscount);
        }

        return new PromoOffer($automatic, $automaticDiscount, codeBeaten: true);
    }

    /**
     * How much comes off.
     *
     * A promo on particular dishes works on those dishes' lines only. Before
     * this, "20% off Jollof" took 20% off everything in the basket.
     *
     * @param  array<int, array{menu_item_id: int|string, amount?: float|int|string|null}>  $lines
     */
    public function calculateDiscount(Promo $promo, float $subtotal, array $lines = []): float
    {
        $base = $this->discountBase($promo, $subtotal, $this->lines($lines, []));

        if ($promo->type === 'percentage') {
            $discount = $base * ((float) $promo->value / 100);
            if ($promo->max_discount !== null) {
                $discount = min($discount, (float) $promo->max_discount);
            }

            return round($discount, 2);
        }

        return round(min((float) $promo->value, $base), 2);
    }

    /**
     * Orders this promo has been used on. A cancelled order gives its use back,
     * and so does a deleted one.
     */
    public function timesUsed(Promo $promo): int
    {
        return Order::query()
            ->where('promo_id', $promo->id)
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    // ── Eligibility ─────────────────────────────────────────────────────────

    /**
     * Why this order cannot have this promo, or null when it can.
     *
     * @param  array<int, array{menu_item_id: int, amount: ?float}>  $lines
     */
    private function refusal(Promo $promo, int $branchId, float $subtotal, array $lines, ?string $phone, ?int $customerId): ?string
    {
        $today = Carbon::today();

        if (! $promo->is_active) {
            return 'inactive';
        }
        if ($promo->start_date && $today->lt($promo->start_date->copy()->startOfDay())) {
            return 'not_started';
        }
        if ($promo->end_date && $today->gt($promo->end_date->copy()->startOfDay())) {
            return 'ended';
        }
        if ($promo->scope === 'branch' && $promo->branches->isNotEmpty()
            && ! $promo->branches->contains('id', $branchId)) {
            return 'branch';
        }
        if ($promo->applies_to === 'items' && $promo->menuItems->isNotEmpty()) {
            $wanted = $promo->menuItems->pluck('id')->all();
            if (array_intersect($wanted, array_column($lines, 'menu_item_id')) === []) {
                return 'items';
            }
        }
        if ($promo->min_order_value !== null && $subtotal < (float) $promo->min_order_value) {
            return 'min_order';
        }
        if ($promo->max_order_value !== null && $subtotal > (float) $promo->max_order_value) {
            return 'max_order';
        }
        if ($promo->max_uses !== null && $this->timesUsed($promo) >= $promo->max_uses) {
            return 'used_up';
        }

        // Everything below depends on who is ordering, which is a phone number.
        // A walk-in at the till has none, so a promo that needs one waits for it.
        $needsCustomer = $promo->first_order_only || $promo->max_uses_per_customer !== null;
        if (! $needsCustomer) {
            return null;
        }
        if ($phone === null) {
            return 'needs_phone';
        }
        if ($promo->first_order_only && $this->ordersBy($phone, $customerId)->exists()) {
            return 'first_order';
        }
        if ($promo->max_uses_per_customer !== null
            && $this->ordersBy($phone, $customerId)->where('promo_id', $promo->id)->count() >= $promo->max_uses_per_customer) {
            return 'per_customer';
        }

        return null;
    }

    /** Words for the person at the screen. */
    private function explain(string $reason, Promo $promo, int $branchId): string
    {
        $code = $promo->code;

        return match ($reason) {
            'inactive' => "{$code} is switched off at the moment.",
            'not_started' => "{$code} starts on {$promo->start_date->format('j F')}.",
            'ended' => "{$code} ended on {$promo->end_date->format('j F')}.",
            'branch' => "{$code} cannot be used at ".(Branch::find($branchId)?->name ?? 'this branch').'.',
            'items' => $this->explainItems($promo),
            'min_order' => "{$code} needs an order of ".$this->money($promo->min_order_value).' or more.',
            'max_order' => "{$code} is for orders up to ".$this->money($promo->max_order_value).'.',
            'used_up' => "{$code} has been used up.",
            'needs_phone' => "{$code} depends on who is ordering. Add the customer's phone number first.",
            'first_order' => "{$code} is for a first order, and this phone number has ordered before.",
            'per_customer' => $promo->max_uses_per_customer === 1
                ? "This phone number has already used {$code}."
                : "This phone number has used {$code} {$promo->max_uses_per_customer} times, which is the limit.",
            default => "{$code} cannot be used on this order.",
        };
    }

    private function explainItems(Promo $promo): string
    {
        $names = $promo->menuItems->pluck('name')->all();

        if (count($names) <= 2) {
            return "{$promo->code} is for ".implode(' or ', $names).'. Add one to the order to use it.';
        }

        return "{$promo->code} is for certain dishes, and none of them is in this order.";
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{menu_item_id: int, amount: ?float}>  $lines
     */
    private function discountBase(Promo $promo, float $subtotal, array $lines): float
    {
        if ($promo->applies_to !== 'items' || $promo->menuItems->isEmpty()) {
            return $subtotal;
        }

        // An older client sent ids without amounts. The subtotal is the only
        // figure it has, and it is what that client always showed.
        if ($lines === [] || in_array(null, array_column($lines, 'amount'), true)) {
            return $subtotal;
        }

        $wanted = $promo->menuItems->pluck('id')->all();

        return (float) array_sum(array_map(
            fn (array $line) => in_array($line['menu_item_id'], $wanted, true) ? $line['amount'] : 0.0,
            $lines,
        ));
    }

    /**
     * @param  array<int, array{menu_item_id: int|string, amount?: float|int|string|null}>  $lines
     * @param  array<int, int|string>  $itemIds
     * @return array<int, array{menu_item_id: int, amount: ?float}>
     */
    private function lines(array $lines, array $itemIds): array
    {
        if ($lines === []) {
            return array_map(fn ($id) => ['menu_item_id' => (int) $id, 'amount' => null], array_values($itemIds));
        }

        return array_map(fn (array $line) => [
            'menu_item_id' => (int) $line['menu_item_id'],
            'amount' => isset($line['amount']) ? (float) $line['amount'] : null,
        ], array_values($lines));
    }

    /**
     * A real Ghana number in +233 form, or null.
     *
     * The till sends 0000000000 for a walk-in who gave no number. That is not a
     * customer, and treating it as one would make every walk-in the same person.
     */
    private function knownPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $normalised = PhoneHelper::normalize($phone);

        return preg_match('/^\+233[1-9][0-9]{8}$/', $normalised) === 1 ? $normalised : null;
    }

    /** Orders placed by this customer, cancelled ones aside. */
    private function ordersBy(string $phone, ?int $customerId): Builder
    {
        return Order::query()
            ->where('status', '!=', 'cancelled')
            ->where(function (Builder $q) use ($phone, $customerId) {
                $q->whereIn('contact_phone', [$phone, PhoneHelper::toLocal($phone)]);
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            });
    }

    private function money(mixed $amount): string
    {
        return '₵'.number_format((float) $amount, 2);
    }
}
