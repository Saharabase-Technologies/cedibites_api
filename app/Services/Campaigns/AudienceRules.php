<?php

namespace App\Services\Campaigns;

use App\Enums\ContactSource;
use App\Enums\GhanaNetwork;

/**
 * A description of who to send to, assembled by the operator.
 *
 * Every rule that is set must hold — they combine with AND, never OR. That is a
 * deliberate limit: "bought jollof OR is on MTN" is almost never what anybody
 * means, and an unrestricted boolean tree is a query builder the operator has to
 * be taught. Stacking narrowing conditions is the mental model people already
 * have from filtering a spreadsheet.
 *
 * An empty rule set matches everybody, so adding a rule can only ever make the
 * audience smaller. That is the property the whole thing rests on: there is no
 * way to accidentally widen a send by editing a filter.
 *
 * Stored as JSON on the campaign so a send can be re-run and audited against the
 * rules it actually used, rather than against whatever the preset means today.
 */
class AudienceRules
{
    /**
     * @param  int|null  $orderedWithinDays  Last order no longer ago than this
     * @param  int|null  $notOrderedForDays  Last order at least this long ago
     * @param  string|null  $orderedAfter  ISO date — first bound of a window
     * @param  string|null  $orderedBefore  ISO date — second bound of a window
     * @param  array<int, int>  $menuItemOptionIds  Bought any one of these menu options — the receipt line
     * @param  array<int, int>  $menuItemIds  Bought any version of any one of these dishes
     * @param  array<int, int>  $branchIds  Ever ordered from any one of these branches
     * @param  array<int, int>  $primaryBranchIds  Buys mostly at one of these branches
     * @param  int|null  $primaryBranchMinOrders  Ignore a primary branch built on fewer orders than this
     * @param  array<int, int>  $onlyBranchIds  Has never ordered anywhere but one of these
     * @param  array<int, string>  $networks  GhanaNetwork values
     * @param  int|null  $hourFrom  Ordered at or after this hour (0–23)
     * @param  int|null  $hourTo  Ordered before this hour (0–23)
     * @param  array<int, string>  $sources  ContactSource values; empty means customers only
     */
    public function __construct(
        public readonly ?int $orderedWithinDays = null,
        public readonly ?int $notOrderedForDays = null,
        public readonly ?string $orderedAfter = null,
        public readonly ?string $orderedBefore = null,
        public readonly array $menuItemIds = [],
        public readonly array $menuItemOptionIds = [],
        public readonly array $branchIds = [],
        public readonly array $primaryBranchIds = [],
        public readonly ?int $primaryBranchMinOrders = null,
        public readonly array $onlyBranchIds = [],
        public readonly array $networks = [],
        public readonly ?int $minOrders = null,
        public readonly ?int $maxOrders = null,
        public readonly ?float $minSpend = null,
        public readonly ?float $maxSpend = null,
        public readonly ?int $hourFrom = null,
        public readonly ?int $hourTo = null,
        public readonly array $sources = [],
    ) {}

    public static function fromArray(?array $rules): self
    {
        $rules ??= [];

        return new self(
            orderedWithinDays: self::intOrNull($rules['ordered_within_days'] ?? null),
            notOrderedForDays: self::intOrNull($rules['not_ordered_for_days'] ?? null),
            orderedAfter: $rules['ordered_after'] ?? null,
            orderedBefore: $rules['ordered_before'] ?? null,
            menuItemIds: array_values(array_map('intval', (array) ($rules['menu_item_ids'] ?? []))),
            menuItemOptionIds: array_values(array_map('intval', (array) ($rules['menu_item_option_ids'] ?? []))),
            branchIds: array_values(array_map('intval', (array) ($rules['branch_ids'] ?? []))),
            primaryBranchIds: array_values(array_map('intval', (array) ($rules['primary_branch_ids'] ?? []))),
            primaryBranchMinOrders: self::intOrNull($rules['primary_branch_min_orders'] ?? null),
            onlyBranchIds: array_values(array_map('intval', (array) ($rules['only_branch_ids'] ?? []))),
            networks: array_values(array_map('strval', (array) ($rules['networks'] ?? []))),
            minOrders: self::intOrNull($rules['min_orders'] ?? null),
            maxOrders: self::intOrNull($rules['max_orders'] ?? null),
            minSpend: isset($rules['min_spend']) && $rules['min_spend'] !== null ? (float) $rules['min_spend'] : null,
            maxSpend: isset($rules['max_spend']) && $rules['max_spend'] !== null ? (float) $rules['max_spend'] : null,
            hourFrom: self::intOrNull($rules['hour_from'] ?? null),
            hourTo: self::intOrNull($rules['hour_to'] ?? null),
            sources: array_values(array_map('strval', (array) ($rules['sources'] ?? []))),
        );
    }

    /** Only the rules actually set, so a stored rule set reads as what was chosen. */
    public function toArray(): array
    {
        return array_filter([
            'ordered_within_days' => $this->orderedWithinDays,
            'not_ordered_for_days' => $this->notOrderedForDays,
            'ordered_after' => $this->orderedAfter,
            'ordered_before' => $this->orderedBefore,
            'menu_item_ids' => $this->menuItemIds ?: null,
            'menu_item_option_ids' => $this->menuItemOptionIds ?: null,
            'branch_ids' => $this->branchIds ?: null,
            'primary_branch_ids' => $this->primaryBranchIds ?: null,
            'primary_branch_min_orders' => $this->primaryBranchMinOrders,
            'only_branch_ids' => $this->onlyBranchIds ?: null,
            'networks' => $this->networks ?: null,
            'min_orders' => $this->minOrders,
            'max_orders' => $this->maxOrders,
            'min_spend' => $this->minSpend,
            'max_spend' => $this->maxSpend,
            'hour_from' => $this->hourFrom,
            'hour_to' => $this->hourTo,

            // Only written when it is not the default. Customers-only is what a
            // rule set means when it says nothing about sources, so recording it
            // explicitly would make every plain audience read as non-empty — and
            // CampaignSender uses isEmpty() to decide whether a campaign is
            // running on a preset or on assembled rules.
            'sources' => $this->isDefaultSources() ? null : $this->sources,
        ], fn ($v) => $v !== null);
    }

    /** Whether anything at all is set. An empty rule set is "everybody". */
    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** Whether resolving these needs the order-items join, which is the expensive one. */
    public function needsItems(): bool
    {
        return $this->menuItemIds !== [] || $this->menuItemOptionIds !== [];
    }

    // ─── Sources ─────────────────────────────────────────────────────────────

    /**
     * The pools to draw from, with the default filled in.
     *
     * @return array<int, string>
     */
    public function effectiveSources(): array
    {
        $valid = array_values(array_filter(
            $this->sources,
            fn (string $s) => ContactSource::tryFrom($s) !== null,
        ));

        return $valid === [] ? [ContactSource::Customers->value] : $valid;
    }

    public function includesCustomers(): bool
    {
        return in_array(ContactSource::Customers->value, $this->effectiveSources(), true);
    }

    /**
     * Whether the supplementary contact base is in scope.
     *
     * The resolver reads this before it touches the contacts table at all, so an
     * audience that has not asked for them costs exactly what it cost before
     * this existed.
     */
    public function includesSupplementary(): bool
    {
        return in_array(ContactSource::Supplementary->value, $this->effectiveSources(), true);
    }

    private function isDefaultSources(): bool
    {
        return $this->effectiveSources() === [ContactSource::Customers->value];
    }

    /**
     * The rules in plain English, for the review step and the audit trail.
     *
     * A campaign's audience should be readable a year later by somebody who was
     * not there when it was built.
     *
     * @return array<int, string>
     */
    public function describe(): array
    {
        $lines = [];

        // First, because it is the only line that can make the audience bigger
        // than "our customers", and it should not be discovered at the bottom of
        // a list of narrowing filters.
        if (! $this->isDefaultSources()) {
            $labels = array_map(
                fn (string $s) => ContactSource::tryFrom($s)?->label() ?? $s,
                $this->effectiveSources(),
            );
            $lines[] = 'Drawn from '.implode(' and ', $labels);
        }

        if ($this->orderedWithinDays !== null) {
            $lines[] = $this->orderedWithinDays === 1
                ? 'Ordered in the last day'
                : "Ordered in the last {$this->orderedWithinDays} days";
        }

        if ($this->notOrderedForDays !== null) {
            $lines[] = "Has not ordered for {$this->notOrderedForDays} days or more";
        }

        if ($this->orderedAfter && $this->orderedBefore) {
            $lines[] = "Ordered between {$this->orderedAfter} and {$this->orderedBefore}";
        } elseif ($this->orderedAfter) {
            $lines[] = "Ordered on or after {$this->orderedAfter}";
        } elseif ($this->orderedBefore) {
            $lines[] = "Ordered on or before {$this->orderedBefore}";
        }

        if ($this->menuItemOptionIds !== []) {
            $lines[] = 'Bought '.count($this->menuItemOptionIds).' selected menu '
                .(count($this->menuItemOptionIds) === 1 ? 'item' : 'items');
        }

        if ($this->menuItemIds !== []) {
            $lines[] = 'Bought any version of '.count($this->menuItemIds).' selected '
                .(count($this->menuItemIds) === 1 ? 'dish' : 'dishes');
        }

        if ($this->branchIds !== []) {
            $lines[] = 'Has ordered from '.count($this->branchIds).' selected '
                .(count($this->branchIds) === 1 ? 'branch' : 'branches');
        }

        if ($this->primaryBranchIds !== []) {
            $line = 'Buys mostly at '.count($this->primaryBranchIds).' selected '
                .(count($this->primaryBranchIds) === 1 ? 'branch' : 'branches');

            if ($this->primaryBranchMinOrders !== null) {
                $line .= ", over {$this->primaryBranchMinOrders} orders or more";
            }

            $lines[] = $line;
        }

        if ($this->onlyBranchIds !== []) {
            $lines[] = 'Has never ordered anywhere but '.count($this->onlyBranchIds).' selected '
                .(count($this->onlyBranchIds) === 1 ? 'branch' : 'branches');
        }

        if ($this->networks !== []) {
            $labels = array_map(
                fn (string $n) => GhanaNetwork::tryFrom($n)?->label() ?? $n,
                $this->networks,
            );
            $lines[] = 'On '.implode(' or ', $labels);
        }

        if ($this->minOrders !== null && $this->maxOrders !== null) {
            $lines[] = "Between {$this->minOrders} and {$this->maxOrders} orders";
        } elseif ($this->minOrders !== null) {
            $lines[] = "{$this->minOrders} orders or more";
        } elseif ($this->maxOrders !== null) {
            $lines[] = "{$this->maxOrders} orders or fewer";
        }

        if ($this->minSpend !== null) {
            $lines[] = 'Spent GHS '.number_format($this->minSpend, 2).' or more';
        }

        if ($this->maxSpend !== null) {
            $lines[] = 'Spent GHS '.number_format($this->maxSpend, 2).' or less';
        }

        if ($this->hourFrom !== null || $this->hourTo !== null) {
            $from = str_pad((string) ($this->hourFrom ?? 0), 2, '0', STR_PAD_LEFT);
            $to = str_pad((string) ($this->hourTo ?? 24), 2, '0', STR_PAD_LEFT);
            $lines[] = "Ordered between {$from}:00 and {$to}:00";
        }

        return $lines ?: ['Everybody we hold a number for'];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
