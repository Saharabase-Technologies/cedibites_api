<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\MenuItemOption;
use App\Models\Order;

/**
 * Fills the blanks in a rule's message for one particular order.
 *
 * Every substitution has a fallback that keeps the sentence readable, because
 * the alternative is what these things are famous for: "Hi , how was your ?".
 * A guest order carries no name and plenty of orders carry no branch, so the
 * missing case is the common case rather than the edge one.
 */
class MessageRenderer
{
    /** What a field becomes when we do not know the answer. */
    private const FALLBACKS = [
        'name' => 'there',
        'dish' => 'your order',
        'branch' => 'CediBites',
        'order_number' => '',
        'link' => '',
    ];

    public function render(AutomationRule $rule, ?Order $order): string
    {
        $values = $this->valuesFor($rule, $order);
        $message = $rule->message;

        foreach ($values as $field => $value) {
            $message = str_replace('{'.$field.'}', $value, $message);
        }

        // Any field the operator invented that we do not fill. Left as an empty
        // string rather than as a literal "{whatever}" — a customer seeing our
        // template syntax is worse than a slightly clipped sentence.
        $message = preg_replace('/\{[a-z_]+\}/', '', $message) ?? $message;

        // Substitutions leave double spaces and stranded punctuation behind.
        return trim(preg_replace('/\s{2,}/', ' ', $message) ?? $message);
    }

    /**
     * @return array<string, string>
     */
    private function valuesFor(AutomationRule $rule, ?Order $order): array
    {
        if (! $order) {
            return self::FALLBACKS;
        }

        $order->loadMissing(['branch:id,name', 'customer.user:id,name']);

        $name = trim((string) ($order->contact_name ?: $order->customer?->user?->name));
        // First names only. "Hi Kwame Mensah Boateng," reads like a summons.
        $firstName = $name !== '' ? explode(' ', $name)[0] : '';

        return [
            'name' => $firstName ?: self::FALLBACKS['name'],
            'dish' => $this->dishFor($rule, $order) ?: self::FALLBACKS['dish'],
            'branch' => $order->branch?->name ?: self::FALLBACKS['branch'],
            'order_number' => (string) ($order->order_number ?? ''),
            'link' => $rule->shortLink?->smsUrl() ?? '',
        ];
    }

    /**
     * What to call the food.
     *
     * For a "tried something new" rule this is the thing they actually just
     * tried, which is the entire reason that rule is interesting — asking "how
     * was the Waakye?" is a different message from "how was your order?".
     * Otherwise it is the first line on the receipt.
     */
    private function dishFor(AutomationRule $rule, Order $order): ?string
    {
        $milestones = new OrderMilestones($order);

        $optionIds = $rule->event === \App\Enums\AutomationEvent::TriedSomethingNew
            ? $milestones->newOptionIds()
            : [];

        if ($optionIds === []) {
            $order->loadMissing('items:id,order_id,menu_item_option_id');
            $first = $order->items->firstWhere('menu_item_option_id', '!=', null);
            $optionIds = $first ? [(int) $first->menu_item_option_id] : [];
        }

        if ($optionIds === []) {
            return null;
        }

        $option = MenuItemOption::with('menuItem:id,name')->find($optionIds[0]);

        if (! $option) {
            return null;
        }

        // The same name the audience builder shows, so a rule targeting an
        // option and a message naming it cannot call it two different things.
        return $option->display_name ?: trim(($option->menuItem?->name ?? '').' '.$option->option_label);
    }
}
