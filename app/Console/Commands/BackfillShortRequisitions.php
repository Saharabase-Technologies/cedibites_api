<?php

namespace App\Console\Commands;

use App\Enums\Inventory\RequisitionStatus;
use App\Enums\Inventory\TransferStatus;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\Transfer;
use Illuminate\Console\Command;

/**
 * One-off repair for requisitions stranded on `approved` by a refused delivery.
 *
 * Before `fulfilled_short` existed, the receive path deliberately withheld
 * fulfilment when anything was refused at the door, "for a corrective run" -
 * but nothing ever performed that run. Those requisitions sat on `approved`
 * permanently, reading in the branch's queue as still-on-its-way long after the
 * lorry had been and gone. Two were live on production.
 *
 * Safe to run more than once: it only ever touches rows still on `approved`
 * whose transfer has already finished, and it moves no stock. The ledger is not
 * involved - this corrects a label, nothing more.
 *
 * Deliberately conservative about which rows it claims:
 *   - the transfer must be `received` or `rejected`. A transfer still in transit
 *     or `disputed` is genuinely unfinished and must stay open (a dispute
 *     spawns a corrective that closes the requisition when it lands).
 *   - something must actually have been refused. A requisition sitting on
 *     `approved` for any OTHER reason is a different bug, and quietly closing
 *     it would bury it.
 */
class BackfillShortRequisitions extends Command
{
    protected $signature = 'inventory:backfill-short-requisitions {--apply : Write the changes. Without this the command only reports.}';

    protected $description = 'Close requisitions stranded on `approved` by a refused delivery';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $stranded = Requisition::query()
            ->where('status', RequisitionStatus::Approved->value)
            ->get()
            ->filter(function (Requisition $r) {
                $transfers = Transfer::where('requisition_id', $r->id)->get();
                if ($transfers->isEmpty()) {
                    return false;
                }

                // Any transfer still running means the request is genuinely open.
                $allFinished = $transfers->every(fn (Transfer $t) => in_array(
                    $t->status, [TransferStatus::Received, TransferStatus::Rejected], true,
                ));

                $refused = $transfers->sum(
                    fn (Transfer $t) => (float) $t->lines()->sum('refused_qty')
                );

                return $allFinished && $refused > 0;
            });

        if ($stranded->isEmpty()) {
            $this->info('Nothing stranded. Every approved requisition is genuinely still open.');

            return self::SUCCESS;
        }

        $this->table(
            ['Reference', 'Branch', 'Refused', 'Transfers'],
            $stranded->map(function (Requisition $r) {
                $transfers = Transfer::where('requisition_id', $r->id)->get();

                return [
                    $r->reference,
                    $r->requestingLocation?->name ?? '-',
                    $transfers->sum(fn (Transfer $t) => (float) $t->lines()->sum('refused_qty')),
                    $transfers->map(fn (Transfer $t) => $t->reference.' ('.$t->status->value.')')->implode(', '),
                ];
            })->all(),
        );

        if (! $apply) {
            $this->warn($stranded->count().' would be closed as `fulfilled_short`. Re-run with --apply to write.');

            return self::SUCCESS;
        }

        foreach ($stranded as $r) {
            $r->update([
                'status' => RequisitionStatus::FulfilledShort,
                // The delivery happened when the transfer was received, not now.
                // Dating it today would put a week-old delivery in this morning's
                // numbers.
                'fulfilled_at' => Transfer::where('requisition_id', $r->id)
                    ->orderByDesc('received_at')
                    ->value('received_at') ?? $r->updated_at,
            ]);
            $this->line("  closed {$r->reference}");
        }

        $this->info($stranded->count().' requisition(s) closed as `fulfilled_short`.');

        return self::SUCCESS;
    }
}
