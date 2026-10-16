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
        $this->loadMissing(['employee.user', 'branch', 'shiftOrders.order', 'managerConfirmedBy.user']);

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
            // Clock out (D29): when, what the person confirmed, their note.
            // Null when the shift ended without one.
            'clockedOutAt' => $this->clocked_out_at?->getTimestamp() * 1000 ?: null,
            'clockOut' => $this->clocked_out_at ? [
                'money' => $this->clock_out_money,
                'owing' => $this->clock_out_owing ?? [],
                'note' => $this->clock_out_note,
            ] : null,
            // The manager's confirm (D30).
            'managerConfirmedAt' => $this->manager_confirmed_at?->getTimestamp() * 1000 ?: null,
            'managerConfirmedBy' => $this->manager_confirmed_at ? $this->managerConfirmedBy?->user?->name : null,
            'managerNote' => $this->manager_note,
        ];
    }
}
