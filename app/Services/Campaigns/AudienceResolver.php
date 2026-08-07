<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignSegment;
use App\Helpers\PhoneHelper;
use App\Models\Customer;
use App\Models\Order;

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
