<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignSegment;
use App\Enums\GhanaNetwork;
use App\Helpers\PhoneHelper;
use App\Models\Customer;
use App\Models\Order;
use Carbon\Carbon;

/**
 * Who is in a segment.
 *
 * Lifted out of CustomerController::exportContacts() so the CSV download and the
 * campaign send read the same definition. That is the whole reason this class
 * exists: two implementations of "churned" would drift, and the day they
 * disagreed nobody would notice — the export would list one set of people and
 * the blast would reach another, both looking correct.
 *
 * Everything is keyed by normalised phone rather than by customer, because a
 * guest who has ordered four times has no customer record and is still somebody
 * we can text. Numbers are validated against the Ghana mobile format and
 * de-duplicated across both sources.
 */
class AudienceResolver
{
    /** Strict Ghana mobile: +233 followed by nine digits starting 2–9. */
    private const PHONE_PATTERN = '/^\+233[2-9]\d{8}$/';

    /**
     * Every contact in a segment.
     *
     * @return array<int, array{name: string, phone: string}>
     */
    public function resolve(CampaignSegment $segment): array
    {
        return $segment === CampaignSegment::All
            ? $this->allContacts()
            : $this->segmentedContacts($segment);
    }

    /**
     * How many people a segment holds.
     *
     * Resolved rather than counted in SQL, because the segments are computed
     * from an aggregate over normalised phone numbers and there is no query that
     * answers it without doing the same work. It is called from the composer to
     * show a live count, so the audience is a scan of orders — acceptable at the
     * volumes involved, and the number has to match the send exactly.
     */
    public function count(CampaignSegment $segment): int
    {
        return count($this->resolve($segment));
    }

    /** Just the numbers, which is all a send needs. */
    public function phones(CampaignSegment $segment): array
    {
        return array_column($this->resolve($segment), 'phone');
    }

    // ─── Custom rules ────────────────────────────────────────────────────────

    /**
     * Everybody matching a set of rules the operator assembled.
     *
     * Built from the same order scan the presets use, so a custom audience and a
     * preset can never disagree about who has ordered what. Every rule narrows;
     * an empty set is everybody.
     *
     * @return array<int, array{name: string, phone: string}>
     */
    public function resolveRules(AudienceRules $rules): array
    {
        if ($rules->isEmpty()) {
            return $this->allContacts();
        }

        $profiles = $this->buildProfiles($rules->needsItems());

        $rows = [];

        foreach ($profiles as $phone => $p) {
            if ($this->matches($p, $phone, $rules)) {
                $rows[] = ['name' => $p['name'], 'phone' => $phone];
            }
        }

        return array_values($rows);
    }

    public function countRules(AudienceRules $rules): int
    {
        return count($this->resolveRules($rules));
    }

    /** @return array<int, string> */
    public function phonesForRules(AudienceRules $rules): array
    {
        return array_column($this->resolveRules($rules), 'phone');
    }

    /**
     * Everything we know about each number's ordering behaviour, in one pass.
     *
     * One scan rather than one per rule: the alternative is a query per filter
     * and an intersection afterwards, which reads the order table five times to
     * answer one question.
     *
     * The item set is only assembled when a rule asks for it — that is the join
     * through order_items, and it is the expensive part.
     *
     * @return array<string, array{
     *     name: string, count: int, last: mixed, spend: float,
     *     branches: array<int, bool>, hours: array<int, bool>, items: array<int, bool>
     * }>
     */
    private function buildProfiles(bool $withItems): array
    {
        $customerPhone = $this->registeredPhones();
        $profiles = [];

        $query = Order::where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select('id', 'customer_id', 'branch_id', 'contact_name', 'contact_phone', 'total_amount', 'created_at');

        if ($withItems) {
            $query->with(['items:id,order_id,menu_item_id']);
        }

        $query->chunk(1000, function ($orders) use (&$profiles, $customerPhone, $withItems): void {
            foreach ($orders as $o) {
                $phone = $this->phoneFor($o, $customerPhone);

                if ($phone === null) {
                    continue;
                }

                if (! isset($profiles[$phone])) {
                    $profiles[$phone] = [
                        'name' => trim((string) $o->contact_name) ?: 'Customer',
                        'count' => 0,
                        // Newest-first, so the first sighting fixes the latest date.
                        'last' => $o->created_at,
                        'spend' => 0.0,
                        'branches' => [],
                        'hours' => [],
                        'items' => [],
                    ];
                }

                $profiles[$phone]['count']++;
                $profiles[$phone]['spend'] += (float) $o->total_amount;

                if ($o->branch_id) {
                    $profiles[$phone]['branches'][(int) $o->branch_id] = true;
                }

                if ($o->created_at) {
                    // Read in PHP rather than SQL on purpose. EXTRACT(HOUR FROM …)
                    // is Postgres-only and is exactly what makes SmartCategoryTest
                    // fail on SQLite depending on the wall clock; this stays
                    // correct on both.
                    $profiles[$phone]['hours'][(int) $o->created_at->hour] = true;
                }

                if ($withItems) {
                    foreach ($o->items as $item) {
                        if ($item->menu_item_id) {
                            $profiles[$phone]['items'][(int) $item->menu_item_id] = true;
                        }
                    }
                }
            }
        });

        return $profiles;
    }

    /** Whether one person's history satisfies every rule that is set. */
    private function matches(array $p, string $phone, AudienceRules $rules): bool
    {
        $daysSince = $p['last'] ? Carbon::parse($p['last'])->diffInDays(now()) : PHP_INT_MAX;

        if ($rules->orderedWithinDays !== null && $daysSince > $rules->orderedWithinDays) {
            return false;
        }

        if ($rules->notOrderedForDays !== null && $daysSince < $rules->notOrderedForDays) {
            return false;
        }

        if ($rules->orderedAfter !== null
            && (! $p['last'] || Carbon::parse($p['last'])->lt(Carbon::parse($rules->orderedAfter)->startOfDay()))) {
            return false;
        }

        if ($rules->orderedBefore !== null
            && (! $p['last'] || Carbon::parse($p['last'])->gt(Carbon::parse($rules->orderedBefore)->endOfDay()))) {
            return false;
        }

        if ($rules->minOrders !== null && $p['count'] < $rules->minOrders) {
            return false;
        }

        if ($rules->maxOrders !== null && $p['count'] > $rules->maxOrders) {
            return false;
        }

        if ($rules->minSpend !== null && $p['spend'] < $rules->minSpend) {
            return false;
        }

        if ($rules->maxSpend !== null && $p['spend'] > $rules->maxSpend) {
            return false;
        }

        // Any one of the chosen branches, dishes or networks is a match — within
        // a single rule the options are alternatives; it is between rules that
        // everything must hold.
        if ($rules->branchIds !== [] && ! array_intersect($rules->branchIds, array_keys($p['branches']))) {
            return false;
        }

        if ($rules->menuItemIds !== [] && ! array_intersect($rules->menuItemIds, array_keys($p['items']))) {
            return false;
        }

        if ($rules->networks !== []) {
            $network = GhanaNetwork::forPhone($phone);

            if (! $network || ! in_array($network->value, $rules->networks, true)) {
                return false;
            }
        }

        if ($rules->hourFrom !== null || $rules->hourTo !== null) {
            $from = $rules->hourFrom ?? 0;
            $to = $rules->hourTo ?? 24;

            $ordered = array_filter(
                array_keys($p['hours']),
                fn (int $hour) => $hour >= $from && $hour < $to,
            );

            if ($ordered === []) {
                return false;
            }
        }

        return true;
    }

    /** The phone an order should be attributed to, or null if there is none. */
    private function phoneFor(Order $order, array $customerPhone): ?string
    {
        $raw = $order->contact_phone;
        $fallback = $order->customer_id ? ($customerPhone[$order->customer_id]['phone'] ?? null) : null;

        if (($raw === null || trim((string) $raw) === '') && $fallback) {
            $raw = $fallback;
        }

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $normalized = PhoneHelper::normalize(trim((string) $raw));

        return $this->isValidPhone($normalized) ? $normalized : null;
    }

    /** Registered customers' own numbers, for orders that carry no contact phone. */
    private function registeredPhones(): array
    {
        $map = [];

        Customer::with('user:id,name,phone')
            ->whereHas('user', fn ($q) => $q->whereNotNull('phone'))
            ->select('id', 'user_id')
            ->chunk(500, function ($customers) use (&$map): void {
                foreach ($customers as $c) {
                    if ($c->user?->phone) {
                        $map[$c->id] = ['name' => $c->user->name, 'phone' => $c->user->phone];
                    }
                }
            });

        return $map;
    }

    public function isValidPhone(string $phone): bool
    {
        return (bool) preg_match(self::PHONE_PATTERN, $phone);
    }

    /**
     * Every valid contact — registered customers (user phone) and guests (order
     * contact phone).
     *
     * @return array<int, array{name: string, phone: string}>
     */
    private function allContacts(): array
    {
        $seen = [];
        $rows = [];

        $add = function (?string $name, ?string $rawPhone) use (&$seen, &$rows): void {
            if ($rawPhone === null || trim($rawPhone) === '') {
                return;
            }

            $normalized = PhoneHelper::normalize(trim($rawPhone));

            if (! $this->isValidPhone($normalized) || isset($seen[$normalized])) {
                return;
            }

            $seen[$normalized] = true;
            $rows[] = ['name' => trim((string) $name) ?: 'Customer', 'phone' => $normalized];
        };

        Customer::with('user:id,name,phone')
            ->whereHas('user', fn ($q) => $q->whereNotNull('phone'))
            ->select('id', 'user_id')
            ->chunk(500, function ($customers) use ($add): void {
                foreach ($customers as $customer) {
                    $add($customer->user?->name, $customer->user?->phone);
                }
            });

        Order::whereNotNull('contact_phone')
            ->where('contact_phone', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select('id', 'contact_name', 'contact_phone', 'created_at')
            ->chunk(1000, function ($orders) use ($add): void {
                foreach ($orders as $order) {
                    $add($order->contact_name, $order->contact_phone);
                }
            });

        return array_values($rows);
    }

    /**
     * Contacts filtered to a behavioural segment, derived from each phone's
     * order history — recency and frequency.
     *
     * @return array<int, array{name: string, phone: string}>
     */
    private function segmentedContacts(CampaignSegment $segment): array
    {
        $now = now();

        // Fallback phone and name for registered customers whose orders carry no
        // contact_phone of their own.
        $customerPhone = [];
        Customer::with('user:id,name,phone')
            ->whereHas('user', fn ($q) => $q->whereNotNull('phone'))
            ->select('id', 'user_id')
            ->chunk(500, function ($customers) use (&$customerPhone): void {
                foreach ($customers as $c) {
                    if ($c->user?->phone) {
                        $customerPhone[$c->id] = ['name' => $c->user->name, 'phone' => $c->user->phone];
                    }
                }
            });

        // Aggregate per normalised phone: order count and most-recent order date.
        // Newest-first, so the first sighting of a phone fixes its latest date
        // and the name we will greet them by.
        $agg = [];
        Order::where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select('id', 'customer_id', 'contact_name', 'contact_phone', 'created_at')
            ->chunk(1000, function ($orders) use (&$agg, $customerPhone): void {
                foreach ($orders as $o) {
                    $rawPhone = $o->contact_phone;
                    $name = $o->contact_name;

                    if (($rawPhone === null || trim((string) $rawPhone) === '') && $o->customer_id && isset($customerPhone[$o->customer_id])) {
                        $rawPhone = $customerPhone[$o->customer_id]['phone'];
                        $name = $name ?: $customerPhone[$o->customer_id]['name'];
                    }

                    if ($rawPhone === null || trim((string) $rawPhone) === '') {
                        continue;
                    }

                    $norm = PhoneHelper::normalize(trim((string) $rawPhone));

                    if (! $this->isValidPhone($norm)) {
                        continue;
                    }

                    if (! isset($agg[$norm])) {
                        $agg[$norm] = ['name' => trim((string) $name) ?: 'Customer', 'count' => 0, 'last' => $o->created_at];
                    }

                    $agg[$norm]['count']++;
                }
            });

        $rows = [];

        foreach ($agg as $phone => $a) {
            $days = $a['last'] ? \Carbon\Carbon::parse($a['last'])->diffInDays($now) : 99999;

            $match = match ($segment) {
                CampaignSegment::Active => $days <= 30,
                CampaignSegment::AtRisk => $days > 30 && $days <= 60,
                CampaignSegment::Churned => $days > 60,
                CampaignSegment::Loyal => $a['count'] >= 2,
                CampaignSegment::OneTime => $a['count'] === 1,
                default => true,
            };

            if ($match) {
                $rows[] = ['name' => $a['name'], 'phone' => $phone];
            }
        }

        return array_values($rows);
    }
}
