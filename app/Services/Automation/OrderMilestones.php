<?php

namespace App\Services\Automation;

use App\Models\Order;
use App\Services\Contacts\PhoneNormaliser;
use Illuminate\Support\Collection;

/**
 * What was true for the first time when this order landed.
 *
 * Every question here is about the order RELATIVE TO WHAT CAME BEFORE IT, which
 * is what separates a milestone from a filter. "Ordered from Ashaiman" is a
 * filter and is true forever; "first order at Ashaiman" is a milestone and is
 * true exactly once, which is the only thing worth firing a message on.
 *
 * The customer's earlier orders are loaded once and every question is answered
 * from that one collection. A rule set with five events would otherwise run five
 * near-identical history queries per completed order.
 */
class OrderMilestones
{
    private ?Collection $previous = null;

    public function __construct(private readonly Order $order) {}

    /** Their first order ever. */
    public function isFirstOrder(): bool
    {
        return $this->previous()->isEmpty();
    }

    /** First time at this branch, even if they have ordered from us elsewhere. */
    public function isFirstAtBranch(): bool
    {
        if (! $this->order->branch_id) {
            return false;
        }

        return ! $this->previous()->contains(
            fn (Order $o) => (int) $o->branch_id === (int) $this->order->branch_id,
        );
    }

    /** First delivery, or first pickup — whichever this one is. */
    public function isFirstOrderType(): bool
    {
        if (! $this->order->order_type) {
            return false;
        }

        return ! $this->previous()->contains(
            fn (Order $o) => $o->order_type === $this->order->order_type,
        );
    }

    /**
     * Bought a menu option they have never bought before.
     *
     * Option-level rather than dish-level: "Jollof Regular → Jollof Large" and
     * "Jollof → Waakye" are different signals, and only the option tells them
     * apart. Returns the new option ids so the message can name what they tried.
     *
     * @return array<int, int>
     */
    public function newOptionIds(): array
    {
        $this->order->loadMissing('items:id,order_id,menu_item_option_id');

        $bought = $this->order->items
            ->pluck('menu_item_option_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($bought->isEmpty()) {
            return [];
        }

        $before = $this->previous()
            ->flatMap(fn (Order $o) => $o->items->pluck('menu_item_option_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $bought->diff($before)->values()->all();
    }

    public function triedSomethingNew(): bool
    {
        // Their first order is not "trying something new" in any useful sense —
        // everything is new. That belongs to the first-order rule, and firing
        // both would be two texts for one event.
        return ! $this->isFirstOrder() && $this->newOptionIds() !== [];
    }

    /** Which number order this is for them. 1 is their first. */
    public function orderNumber(): int
    {
        return $this->previous()->count() + 1;
    }

    /**
     * Days since their previous order, or null if this is their first.
     *
     * Measured to the previous order rather than to now, because the question a
     * win-back rule asks is "how long had they been gone when they came back?".
     */
    public function daysSincePrevious(): ?int
    {
        $last = $this->previous()->first();

        if (! $last || ! $last->created_at || ! $this->order->created_at) {
            return null;
        }

        return (int) $last->created_at->diffInDays($this->order->created_at);
    }

    public function amount(): float
    {
        return (float) $this->order->total_amount;
    }

    /**
     * Everything this customer ordered before this one, newest first.
     *
     * Matched on the phone in every shape it might have been stored, plus the
     * customer id when there is one — `orders.contact_phone` holds whatever was
     * typed at the counter. See PhoneNormaliser::variants() for what this does
     * not catch.
     */
    private function previous(): Collection
    {
        if ($this->previous !== null) {
            return $this->previous;
        }

        $phone = $this->phone();

        if ($phone === null && ! $this->order->customer_id) {
            return $this->previous = collect();
        }

        $query = Order::where('status', '!=', 'cancelled')
            ->with('items:id,order_id,menu_item_option_id')
            ->where(function ($q) use ($phone) {
                if ($phone !== null) {
                    $q->whereIn('contact_phone', PhoneNormaliser::variants($phone));
                }

                if ($this->order->customer_id) {
                    $q->orWhere('customer_id', $this->order->customer_id);
                }
            })
            // Strictly earlier. Compared on id as well as time so two orders in
            // the same second cannot both count as preceding each other.
            ->where(function ($q) {
                $q->where('created_at', '<', $this->order->created_at)
                    ->orWhere(function ($inner) {
                        $inner->where('created_at', $this->order->created_at)
                            ->where('id', '<', $this->order->id);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->previous = $query->get();
    }

    /**
     * Everything before this order, newest first.
     *
     * Exposed so the evaluator can build an audience profile from history it has
     * already loaded rather than querying for it a second time.
     *
     * @return array<int, Order>
     */
    public function previousOrders(): array
    {
        return $this->previous()->all();
    }

    /** The number this order should be attributed to. */
    public function phone(): ?string
    {
        $raw = trim((string) $this->order->contact_phone);

        if ($raw === '') {
            $this->order->loadMissing('customer.user');
            $raw = trim((string) $this->order->customer?->user?->phone);
        }

        return $raw === '' ? null : PhoneNormaliser::normalise($raw);
    }
}
