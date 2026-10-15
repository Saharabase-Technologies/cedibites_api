<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The money taken, by method, from the payments themselves (D11).
 *
 * Never one mixed total: cash is counted against the drawer, MoMo is checked
 * with Hubtel, and adding them together hides a short drawer behind a busy
 * MoMo day.
 */
class ShiftMoney
{
    /** How each payment method is counted at the end of a shift. */
    private const GROUPS = [
        'cash' => 'cash',
        'cash_on_delivery' => 'cash',
        // A prompt the till sent, which Hubtel confirmed.
        'mobile_money' => 'momo',
        // A transfer the cashier typed a reference for. Only their word.
        'manual_momo' => 'manual_momo',
        'card' => 'card',
        'wallet' => 'other',
        'ghqr' => 'other',
    ];

    /**
     * What one shift took.
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public function for(Shift $shift): array
    {
        return $this->sum(Payment::where('shift_id', $shift->id));
    }

    /**
     * What one person took between two times, on a shift or not (D9).
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public function forCollector(int $employeeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->sum(Payment::where('collected_by', $employeeId)->whereBetween('paid_at', [$from, $to]));
    }

    /** @return array<string, array{amount: float, count: int}> */
    private function sum(Builder $payments): array
    {
        $money = array_fill_keys(['cash', 'momo', 'manual_momo', 'card', 'other'], ['amount' => 0.0, 'count' => 0]);

        $rows = $payments
            ->where('payment_status', 'completed')
            ->selectRaw('payment_method, COUNT(*) as n, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        foreach ($rows as $row) {
            $group = self::GROUPS[$row->payment_method] ?? 'other';
            $money[$group]['amount'] = round($money[$group]['amount'] + (float) $row->total, 2);
            $money[$group]['count'] += (int) $row->n;
        }

        return $money;
    }
}
