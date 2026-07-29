<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $lastOrder = $this->relationLoaded('orders') && $this->orders->isNotEmpty()
            ? $this->orders->first()
            : $this->orders()->latest()->first();

        // For guest customers, get name and phone from their most recent order
        $name = $user?->name ?? $lastOrder?->contact_name ?? 'Guest Customer';
        $phone = $user?->phone ?? $lastOrder?->contact_phone ?? '';

        // Get the most ordered menu item
        $mostOrderedItem = $this->orders()
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->join('menu_item_options', 'order_items.menu_item_option_id', '=', 'menu_item_options.id')
            ->join('menu_items', 'menu_item_options.menu_item_id', '=', 'menu_items.id')
            ->selectRaw('menu_items.name, SUM(order_items.quantity) as total_quantity')
            ->groupBy('menu_items.id', 'menu_items.name')
            ->orderByDesc('total_quantity')
            ->first();

        return [
            'id' => (string) $this->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $user?->email,
            'is_guest' => $this->is_guest,
            'account_type' => $this->is_guest ? 'Guest' : 'Registered',
            'status' => $this->status ?? 'active',
            'total_orders' => $this->orders_count ?? 0,
            'total_spend' => (float) ($this->total_spend ?? 0),
            'last_order_at' => $lastOrder?->created_at?->toIso8601String(),
            'join_date' => $this->created_at?->format('M Y'),
            'addresses' => $this->addresses->map(fn ($a) => $a->full_address)->filter()->values()->all(),
            'also_known_as' => $this->whenLoaded('orderAliases', fn () => $this->aliases($name)),
            'most_ordered_item' => $mostOrderedItem?->name ?? '—',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Other names this person has given at the counter, newest first.
     *
     * An order records the name spoken at the time and never writes it back to
     * the account, so a customer saved as "Akosua" who orders as "Philippa"
     * keeps both: Akosua on the profile, Philippa on that receipt. Surfacing the
     * difference here is what makes that split usable — the call centre can
     * still match a caller who gives a name the profile has never held.
     *
     * @return array<int, string>
     */
    private function aliases(string $canonicalName): array
    {
        $canonical = mb_strtolower(trim($canonicalName));

        return $this->orderAliases
            ->pluck('contact_name')
            ->map(fn (?string $n): string => trim((string) $n))
            ->filter(fn (string $n): bool => $n !== '' && mb_strtolower($n) !== $canonical)
            // Case-insensitive de-dupe, keeping the most recent spelling.
            ->unique(fn (string $n): string => mb_strtolower($n))
            ->take(5)
            ->values()
            ->all();
    }
}
