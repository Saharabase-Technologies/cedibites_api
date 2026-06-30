<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // Goods only — the restaurant's share, excluding the third-party delivery
        // fee. min() keeps it correct for both historical rows (amount = full total)
        // and post-cutover rows (amount already = goods).
        $order = $this->relationLoaded('order') ? $this->order : null;
        $goodsAmount = $order
            ? round(min((float) $this->amount, (float) $order->goods_amount), 2)
            : (float) $this->amount;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'amount' => $this->amount,
            'goods_amount' => $goodsAmount,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'transaction_id' => $this->transaction_id,
            'checkout_url' => $this->payment_gateway_response['checkoutUrl'] ?? null,
            'checkout_direct_url' => $this->payment_gateway_response['checkoutDirectUrl'] ?? null,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'order_source' => $this->order->order_source,
                'contact_name' => $this->order->contact_name,
                'contact_phone' => $this->order->contact_phone,
                'customer' => [
                    'name' => $this->order->customer->user->name ?? null,
                    'phone' => $this->order->customer->user->phone ?? null,
                ],
            ]),
        ];
    }
}
