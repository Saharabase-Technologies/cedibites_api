<?php

namespace App\Domain\Inventory\Support;

use Illuminate\Support\Facades\DB;

/**
 * Generates date-stamped, per-day sequential references that can be read at a
 * glance, e.g. PO-260701-001 / RCP-260701-001 (prefix-YYMMDD-NNN). The 3-digit
 * counter resets each day.
 *
 * Must be called inside the same DB::transaction() that inserts the row so the
 * lockForUpdate() window covers the read-increment-write race.
 */
class ReferenceGenerator
{
    public function purchaseOrder(): string
    {
        return $this->next('inventory_purchase_orders', 'PO');
    }

    public function purchase(): string
    {
        return $this->next('inventory_purchases', 'RCP');
    }

    public function production(): string
    {
        return $this->next('inventory_production_logs', 'PROD');
    }

    private function next(string $table, string $prefix): string
    {
        $date = now()->format('ymd');
        $like = "{$prefix}-{$date}-%";

        $last = DB::table($table)
            ->where('reference', 'like', $like)
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $seq = $last ? ((int) substr($last, -3)) + 1 : 1;

        return sprintf('%s-%s-%03d', $prefix, $date, $seq);
    }
}
