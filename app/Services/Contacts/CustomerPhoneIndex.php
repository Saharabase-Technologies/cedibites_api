<?php

namespace App\Services\Contacts;

use App\Models\Customer;
use App\Models\Order;

/**
 * Every phone number that has ever bought from us, and the order that did it
 * first.
 *
 * Built by scanning orders and normalising in PHP rather than by querying for
 * the numbers we are interested in. That looks wasteful and is not optional:
 * `orders.contact_phone` holds whatever was typed at the counter — 0241234567,
 * +233241234567, 233 24 123 4567 — so a `whereIn` over normalised numbers
 * matches only the rows that happened to be entered in the same shape as the
 * CSV. It would find some of the existing customers in an uploaded list and miss
 * the rest, which is worse than finding none: the import would report a
 * plausible acquisition figure that was quietly wrong.
 *
 * AudienceResolver scans the same table the same way for the same reason. Built
 * once per import, never cached across requests.
 */
class CustomerPhoneIndex
{
    /**
     * Oldest order first, so the first sighting of a number fixes the moment it
     * became a customer.
     *
     * @return array<string, array{order_id: int, ordered_at: \Illuminate\Support\Carbon|null, customer_id: int|null}>
     */
    public function build(): array
    {
        $index = [];
        $customerPhones = $this->registeredPhones();

        Order::where('status', '!=', 'cancelled')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select('id', 'customer_id', 'contact_phone', 'created_at')
            ->chunk(1000, function ($orders) use (&$index, $customerPhones): void {
                foreach ($orders as $order) {
                    $raw = trim((string) $order->contact_phone);

                    if ($raw === '' && $order->customer_id) {
                        $raw = (string) ($customerPhones[$order->customer_id] ?? '');
                    }

                    $phone = PhoneNormaliser::normalise($raw);

                    if ($phone === null || isset($index[$phone])) {
                        continue;
                    }

                    $index[$phone] = [
                        'order_id' => (int) $order->id,
                        'ordered_at' => $order->created_at,
                        'customer_id' => $order->customer_id ? (int) $order->customer_id : null,
                    ];
                }
            });

        return $index;
    }

    /**
     * Registered customers' own numbers, for orders that carry no contact phone.
     *
     * @return array<int, string>
     */
    private function registeredPhones(): array
    {
        $map = [];

        Customer::with('user:id,phone')
            ->whereHas('user', fn ($q) => $q->whereNotNull('phone'))
            ->select('id', 'user_id')
            ->chunk(500, function ($customers) use (&$map): void {
                foreach ($customers as $customer) {
                    if ($customer->user?->phone) {
                        $map[$customer->id] = $customer->user->phone;
                    }
                }
            });

        return $map;
    }
}
