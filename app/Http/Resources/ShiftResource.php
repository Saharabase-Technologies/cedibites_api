<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Matches frontend StaffShift interface.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['employee.user', 'branch', 'shiftOrders.order']);

        // Third-party delivery fees are a pass-through collected on the customer's
        // behalf, not restaurant revenue. Split the stored gross total_sales into
        // goods (revenue) and delivery so the shift reconciles: goods + delivery = total.
        $deliveryFees = round(
            $this->shiftOrders->sum(fn ($shiftOrder) => (float) ($shiftOrder->order?->delivery_fee ?? 0)),
            2
        );
        $totalSales = (float) $this->total_sales;
        $goodsSales = round($totalSales - $deliveryFees, 2);

        return [
            'id' => (string) $this->id,
            'staffId' => (string) $this->employee_id,
            'staffName' => $this->employee?->user?->name ?? '',
            // Null for a call-centre shift, which belongs to no branch — its
            // takings break down by the branches its orders went to.
            'branchId' => $this->branch_id === null ? null : (string) $this->branch_id,
            'branchName' => $this->branch?->name ?? '',
            'loginAt' => $this->login_at->getTimestamp() * 1000,
            'logoutAt' => $this->logout_at?->getTimestamp() * 1000,
            'orderIds' => $this->shiftOrders->pluck('order.order_number')->filter()->values()->all(),
            'totalSales' => $totalSales,
            'goodsSales' => $goodsSales,
            'deliveryFees' => $deliveryFees,
            'orderCount' => (int) $this->order_count,
        ];
    }
}
