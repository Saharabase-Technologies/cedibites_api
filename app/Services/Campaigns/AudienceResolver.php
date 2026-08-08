<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignSegment;
use App\Enums\GhanaNetwork;
use App\Helpers\PhoneHelper;
use App\Models\Contact;
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

        $profiles = $this->buildProfiles($rules->needsItems(), $rules->includesSupplementary());

        $rows = [];

        foreach ($profiles as $phone => $p) {
            if (! $this->inScope($p, $rules)) {
                continue;
            }

            if ($this->matches($p, $phone, $rules)) {
                $rows[] = ['name' => $p['name'], 'phone' => $phone];
            }
        }

        return array_values($rows);
    }

    /**
     * Whether this number is in one of the pools the operator asked for.
     *
     * The two pools are a partition and this is where that is enforced:
     * supplementary requires `is_imported AND NOT is_customer`. Anybody who has
     * ordered belongs to Customers and only to Customers, whatever list we
     * originally found them on.
     *
     * The `! is_customer` half is not redundant with the unconverted() filter
     * that seeds them. A contact whose order never reached ContactConverter —
     * written by a seeder, a backfill, or before `contacts:reconcile` last ran —
     * still has `converted_at` null while plainly having orders. Reading the
     * order scan rather than the flag means the partition holds even when the
     * conversion bookkeeping is behind, instead of quietly counting a regular
     * customer as a prospect and texting them as one.
     *
     * Applied after the behavioural rules rather than folded into them, so the
     * rules stay purely about behaviour.
     */
    private function inScope(array $profile, AudienceRules $rules): bool
    {
        if ($profile['is_customer']) {
            return $rules->includesCustomers();
        }

        return $rules->includesSupplementary() && $profile['is_imported'];
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
     * Seeded before the scan with everybody we hold a number for, each starting
     * at zero orders, so that a rule set with nothing behavioural in it matches
     * exactly who allContacts() returns. Without the seed, adding any rule at
     * all silently dropped registered account holders who have never ordered —
     * they appear in no order row, so the scan alone cannot see them. A
     * zero-profile then answers every behavioural rule correctly on its own
     * terms: it fails "ordered in the last 30 days", passes "has not ordered for
     * 60 days", fails any dish or branch, and carries a network read off the
     * prefix.
     *
     * @return array<string, array{
     *     name: string, count: int, last: mixed, spend: float,
     *     branches: array<int, bool>, hours: array<int, bool>, items: array<int, bool>,
     *     is_customer: bool, is_imported: bool
     * }>
     */
    private function buildProfiles(bool $withItems, bool $withSupplementary = false): array
    {
        $customerPhone = $this->registeredPhones();
        $profiles = [];

        // Registered account holders first, so their own name wins over whatever
        // was typed at a counter — the same precedence allContacts() uses.
        foreach ($customerPhone as $entry) {
            $phone = PhoneHelper::normalize(trim((string) $entry['phone']));

            if ($this->isValidPhone($phone) && ! isset($profiles[$phone])) {
                $profiles[$phone] = $this->blankProfile($entry['name'], isCustomer: true);
            }
        }

        /*
         * The supplementary contact base, only when the audience asked for it.
         * Left untouched otherwise, so a customers-only audience does not pay
         * for a table it is not going to read.
         *
         * Unconverted rows only. A contact who has ordered is a customer and is
         * already in this map through the order scan below; seeding them here
         * as well would put the same person on both sides of what is supposed
         * to be a partition.
         */
        if ($withSupplementary) {
            Contact::unconverted()
                ->select('name', 'phone')
                ->chunk(2000, function ($contacts) use (&$profiles): void {
                    foreach ($contacts as $contact) {
                        if (isset($profiles[$contact->phone])) {
                            // Same number as a registered account holder. Mark
                            // the origin and keep the richer profile; inScope()
                            // will file them under Customers.
                            $profiles[$contact->phone]['is_imported'] = true;

                            continue;
                        }

                        if ($this->isValidPhone($contact->phone)) {
                            $profiles[$contact->phone] = $this->blankProfile($contact->name, isImported: true);
                        }
                    }
                });
        }

        $query = Order::where('status', '!=', 'cancelled')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->select('id', 'customer_id', 'branch_id', 'contact_name', 'contact_phone', 'total_amount', 'created_at');

        if ($withItems) {
            // The option is what was actually bought — "Jollof, Large" is the
            // receipt line and the thing a promotion is about. The item id is
            // carried too because it is the broader net and, unlike the option,
            // it survives: menu_item_option_id is nullable and set to null when
            // an option is deleted, so option-level history can disappear from
            // old orders while item-level history never does.
            $query->with(['items:id,order_id,menu_item_id,menu_item_option_id']);
        }

        $query->chunk(1000, function ($orders) use (&$profiles, $customerPhone, $withItems): void {
            foreach ($orders as $o) {
                $phone = $this->phoneFor($o, $customerPhone);

                if ($phone === null) {
                    continue;
                }

                if (! isset($profiles[$phone])) {
                    $profiles[$phone] = $this->blankProfile($o->contact_name, isCustomer: true);
                }

                // Placing an order is what makes somebody a customer, whether or
                // not they hold an account — this is the same line the contact
                // base draws with converted_at.
                $profiles[$phone]['is_customer'] = true;

                // Newest-first, so the first order seen for a number fixes its
                // latest date. A seeded profile has none yet.
                $profiles[$phone]['last'] ??= $o->created_at;

                $profiles[$phone]['count']++;
                $profiles[$phone]['spend'] += (float) $o->total_amount;

                if ($o->branch_id) {
                    $branchId = (int) $o->branch_id;

                    // Counted, not flagged. "Ever ordered here" and "this is
                    // their branch" are different questions, and only a count
                    // can answer the second — one visit to Ashaiman three years
                    // ago should not put somebody in Ashaiman's audience forever.
                    $profiles[$phone]['branches'][$branchId] =
                        ($profiles[$phone]['branches'][$branchId] ?? 0) + 1;

                    // Newest-first, so the first branch seen is the most recent
                    // one. Breaks ties for the primary branch.
                    $profiles[$phone]['last_branch'] ??= $branchId;
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
                        if ($item->menu_item_option_id) {
                            $profiles[$phone]['options'][(int) $item->menu_item_option_id] = true;
                        }

                        if ($item->menu_item_id) {
                            $profiles[$phone]['items'][(int) $item->menu_item_id] = true;
                        }
                    }
                }
            }
        });

        return $profiles;
    }

    /**
     * Somebody we hold a number for who has, so far, done nothing.
     *
     * `last` is null rather than a date, which is what makes the recency rules
     * treat "never ordered" as infinitely long ago instead of as today.
     */
    private function blankProfile(?string $name, bool $isCustomer = false, bool $isImported = false): array
    {
        return [
            'name' => trim((string) $name) ?: 'Customer',
            'count' => 0,
            'last' => null,
            'spend' => 0.0,
            /** @var array<int, int> branch id => how many orders there */
            'branches' => [],
            'last_branch' => null,
            'hours' => [],
            'items' => [],
            'options' => [],
            'is_customer' => $isCustomer,
            'is_imported' => $isImported,
        ];
    }

    /**
     * The branch somebody belongs to — where they have bought the most.
     *
     * The nearest thing to a home branch we can know without asking. We hold no
     * customer location, so purchase history is the honest proxy.
     *
     * Ties go to the most recent branch, which makes the answer deterministic
     * and picks the more useful of the two: somebody two-and-two between
     * branches is better reached about the one they were at last.
     *
     * Means little for a customer with a single order — their "primary" branch
     * is just their only one — which is why the rule that uses it can require a
     * minimum number of orders.
     */
    private function primaryBranch(array $profile): ?int
    {
        if ($profile['branches'] === []) {
            return null;
        }

        $most = max($profile['branches']);
        $leaders = array_keys($profile['branches'], $most, true);

        if (count($leaders) === 1) {
            return (int) $leaders[0];
        }

        return in_array($profile['last_branch'], $leaders, true)
            ? (int) $profile['last_branch']
            : (int) $leaders[0];
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

        // Where they belong, not merely where they have been. See primaryBranch().
        if ($rules->primaryBranchIds !== []) {
            $primary = $this->primaryBranch($p);

            if ($primary === null || ! in_array($primary, $rules->primaryBranchIds, true)) {
                return false;
            }

            // A single order makes a primary branch that means nothing. The
            // threshold is opt-in so the rule can still be used loosely.
            if ($rules->primaryBranchMinOrders !== null && $p['count'] < $rules->primaryBranchMinOrders) {
                return false;
            }
        }

        // Never bought anywhere else. The strictest of the three, and the right
        // one for something only that branch can honour.
        if ($rules->onlyBranchIds !== []) {
            $branches = array_keys($p['branches']);

            if (count($branches) !== 1 || ! in_array((int) $branches[0], $rules->onlyBranchIds, true)) {
                return false;
            }
        }

        if ($rules->menuItemOptionIds !== [] && ! array_intersect($rules->menuItemOptionIds, array_keys($p['options']))) {
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
