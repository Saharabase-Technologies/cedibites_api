<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\PaymentCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills in who took each payment made before payments recorded it.
 *
 * Until October 2026 a payment did not say who took the money. The best guess
 * is the person named on the order, who rang it at the till. Every row this
 * fills is marked collector_inferred, so the guess is never read as a record.
 * An online customer's payment gets its source and no collector.
 *
 * Shows counts only, unless --apply. --shifts also puts each payment on the
 * shift its person was on when it was paid. That is a second guess on top of
 * the first, so it is a choice of its own.
 *
 * Writes through the query builder: no model events, so the payment observer
 * (which moves stock) never runs, and updated_at is left as it was.
 */
class BackfillPaymentCollectors extends Command
{
    protected $signature = 'payments:backfill-collectors
                            {--apply : Write the changes. Without it, only the counts are shown}
                            {--shifts : Also fill shift_id from the shift each person was on when it was paid}';

    protected $description = 'Fill in who took each past payment, from the person on its order, marked as inferred';

    public function handle(PaymentCollector $collector): int
    {
        $apply = (bool) $this->option('apply');
        $withShifts = (bool) $this->option('shifts');

        if (! $apply) {
            $this->warn('Counts only. Nothing is written without --apply.');
        }

        $counts = ['till' => 0, 'online' => 0, 'manual_entry' => 0, 'no order' => 0, 'no person on the order' => 0, 'on a shift' => 0];

        Payment::query()
            ->whereNull('source')
            ->with(['order' => fn ($q) => $q->withTrashed()->select('id', 'branch_id', 'assigned_employee_id', 'order_source')])
            ->chunkById(500, function ($payments) use ($collector, $apply, $withShifts, &$counts) {
                foreach ($payments as $payment) {
                    $order = $payment->order;

                    if (! $order) {
                        $counts['no order']++;

                        continue;
                    }

                    $source = match ($order->order_source) {
                        'online' => PaymentCollector::SOURCE_ONLINE,
                        'manual_entry' => PaymentCollector::SOURCE_MANUAL_ENTRY,
                        default => PaymentCollector::SOURCE_TILL,
                    };
                    $employeeId = $source === PaymentCollector::SOURCE_ONLINE ? null : $order->assigned_employee_id;

                    $counts[$source]++;

                    if ($employeeId === null && $source !== PaymentCollector::SOURCE_ONLINE) {
                        $counts['no person on the order']++;
                    }

                    $shiftId = $withShifts && $employeeId && $payment->paid_at
                        ? $collector->shiftAt($employeeId, $order->branch_id, $payment->paid_at)
                        : null;

                    if ($shiftId) {
                        $counts['on a shift']++;
                    }

                    if ($apply) {
                        DB::table('payments')->where('id', $payment->id)->update(array_filter([
                            'source' => $source,
                            'collected_by' => $employeeId,
                            'collector_inferred' => $employeeId !== null,
                            'shift_id' => $shiftId,
                        ], fn ($value) => $value !== null));
                    }
                }
            });

        $this->table(['', 'Payments'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        return self::SUCCESS;
    }
}
