<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'order_type' => $this->order_type,
            'order_source' => $this->order_source,
            'payment_method' => $this->payment_method,
            'subtotal' => (float) $this->subtotal,
            'delivery_fee' => (float) $this->delivery_fee,
            'delivery_fee_status' => $this->delivery_fee_status,
            'delivery_fee_collected_at' => $this->delivery_fee_collected_at?->toISOString(),
            'goods_amount' => (float) $this->goods_amount,
            'total_amount' => (float) $this->total_amount,
            'discount' => (float) $this->discount,
            'promo_id' => $this->promo_id,
            'promo_name' => $this->promo_name,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'delivery_address' => $this->delivery_address,
            'delivery_note' => $this->delivery_note,
            'branch_id' => $this->branch_id,
            'branch' => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name ?? '—',
                'address' => $this->branch?->address,
                'phone' => $this->branch?->phone,
                'latitude' => $this->branch?->latitude,
                'longitude' => $this->branch?->longitude,
            ],
            // Whether a slip has ever been produced for this order — the till
            // shows "Print receipt" until it has, and "Reprint" after.
            'receipt_printed_at' => $this->receipt_printed_at?->toISOString(),
            'receipt_print_count' => (int) $this->receipt_print_count,
            'assigned_employee_id' => $this->assigned_employee_id,
            'staff_name' => $this->assignedEmployee?->user?->name,
            'assigned_employee' => $this->assignedEmployee ? [
                'id' => $this->assignedEmployee->id,
                'name' => $this->assignedEmployee->user?->name,
                'phone' => $this->assignedEmployee->user?->phone,
            ] : null,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'menu_item_option_id' => $item->menu_item_option_id,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'special_instructions' => $item->special_instructions,
                'menu_item_snapshot' => $item->menu_item_snapshot,
                'menu_item' => [
                    'id' => $item->menuItem?->id,
                    'name' => $item->menuItem?->name,
                    'category' => $item->menuItem?->category?->name,
                ],
                // Lets the board grey out the lines the kitchen has nothing to
                // do with — a drink on an otherwise hot order. Defaults to true
                // when the menu item has been deleted out from under the order,
                // so a line never silently reads as "ignore me".
                'requires_preparation' => $item->menuItem?->requiresPreparation() ?? true,
                'option' => $item->menuItemOption ? [
                    'id' => $item->menuItemOption->id,
                    'option_key' => $item->menuItemOption->option_key,
                    'option_label' => $item->menuItemOption->option_label,
                    'display_name' => $item->menuItemOption->display_name,
                    'image_url' => $item->menuItemOption->getFirstMediaUrl('menu-item-options') ?: null,
                ] : null,
                'option_snapshot' => $item->menu_item_option_snapshot,
            ]),
            'customer' => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->user?->name,
                'phone' => $this->customer->user?->phone,
            ] : null,
            'payments' => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'payment_status' => $payment->payment_status,
                'amount' => (float) $payment->amount,
                'transaction_id' => $payment->transaction_id,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ]),
            'payment' => $this->payments->first() ? [
                'id' => $this->payments->first()->id,
                'payment_method' => $this->payments->first()->payment_method,
                'payment_status' => $this->payments->first()->payment_status,
                'amount' => (float) $this->payments->first()->amount,
                'transaction_id' => $this->payments->first()->transaction_id,
                'paid_at' => $this->payments->first()->paid_at?->toIso8601String(),
            ] : null,
            /**
             * When this order entered the status it is in now.
             *
             * The boards need to say "cooking for 2 minutes", not "placed 15
             * minutes ago", and they cannot work that out for themselves: a
             * screen only knows about the moves it made, so after a refresh —
             * or for an order bumped on a different tablet — it had nothing to
             * go on and fell back to the placed time. Every stage then read as
             * the order's total age.
             *
             * `order_status_history` already records `changed_at` for every
             * transition, so the answer is authoritative here and identical on
             * every screen looking at it.
             *
             * Null when the relation was not loaded — callers fall back to the
             * placed time rather than this quietly triggering a query per order.
             */
            'stage_changed_at' => $this->relationLoaded('statusHistory')
                ? $this->statusHistory
                    ->where('status', $this->status)
                    ->sortByDesc(fn ($h) => $h->changed_at ?? $h->created_at)
                    ->first()?->changed_at?->toIso8601String()
                : null,
            'status_history' => $this->statusHistory->map(fn ($history) => [
                'id' => $history->id,
                'status' => $history->status,
                'notes' => $history->notes,
                'changed_by_type' => $history->changed_by_type,
                'changed_by' => $history->changedBy ? [
                    'id' => $history->changedBy->id,
                    'name' => $history->changedBy->name,
                ] : null,
                'changed_at' => $history->changed_at?->toIso8601String(),
                'created_at' => $history->created_at?->toIso8601String(),
            ]),
            // Cancel request fields
            'cancel_requested_by' => $this->cancel_requested_by,
            'cancel_request_reason' => $this->cancel_request_reason,
            'cancel_requested_at' => $this->cancel_requested_at?->toIso8601String(),
            'cancel_requested_by_user' => $this->cancelRequestedBy ? [
                'id' => $this->cancelRequestedBy->id,
                'name' => $this->cancelRequestedBy->name,
            ] : null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_reason' => $this->cancelled_reason,
            'internal_notes' => $this->internal_notes ?? [],
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
