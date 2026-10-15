<?php

namespace App\Services\Payments;

use App\Models\Shift;
use Carbon\CarbonInterface;

/**
 * Who took a payment, and on which shift (D8, D9).
 *
 * The sale belongs to whoever placed the order. The money belongs to whoever
 * took it. So every payment records the person who took it and the shift they
 * were on, and a shift adds up only its own person's money, on the server.
 */
class PaymentCollector
{
    /** Rung at a till or by the call centre. */
    public const SOURCE_TILL = 'till';

    /** Paid by the customer online. Nobody on staff took it. */
    public const SOURCE_ONLINE = 'online';

    /** A recorded past order. */
    public const SOURCE_MANUAL_ENTRY = 'manual_entry';

    /**
     * The columns for a payment taken by this employee at this branch.
     *
     * $at is when they took it. For a MoMo prompt that is when the cashier
     * sent it, so a prompt paid after their shift ends is still theirs, on the
     * shift they were on when they sent it.
     *
     * @return array{collected_by: ?int, shift_id: ?int, source: string}
     */
    public function stamp(?int $employeeId, ?int $branchId, string $source, ?CarbonInterface $at = null): array
    {
        return [
            'collected_by' => $employeeId,
            'shift_id' => $employeeId ? $this->shiftAt($employeeId, $branchId, $at ?? now()) : null,
            'source' => $source,
        ];
    }

    /** The shift the employee was on at that moment, at that branch. */
    public function shiftAt(int $employeeId, ?int $branchId, CarbonInterface $at): ?int
    {
        return Shift::where('employee_id', $employeeId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('login_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('logout_at')->orWhere('logout_at', '>=', $at))
            ->latest('login_at')
            ->value('id');
    }
}
