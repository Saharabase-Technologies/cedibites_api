<?php

namespace App\Console\Commands;

use App\Services\BranchProvisioningService;
use Illuminate\Console\Command;

/**
 * Give every active branch an inventory location.
 *
 * The catalog seeder creates one for the first four branches and nothing
 * provisions a branch added afterwards, so this drifts. A branch with no
 * location is invisible in the inventory portal to its own manager, and its
 * sales fall through to debiting the mother kitchen — both silently.
 *
 * BranchProvisioningService now handles this at creation time. This is the
 * repair for branches that predate it. Idempotent.
 */
class BranchProvisionLocations extends Command
{
    protected $signature = 'branch:provision-locations
                            {--dry-run : List what would be created without creating it}';

    protected $description = 'Create the missing inventory location for any active branch that has none';

    public function handle(BranchProvisioningService $provisioning): int
    {
        $missing = $provisioning->unprovisionedBranches();

        if ($missing->isEmpty()) {
            $this->info('Every active branch already has an inventory location.');

            return self::SUCCESS;
        }

        $this->warn($missing->count().' active branch(es) have no inventory location:');
        foreach ($missing as $branch) {
            $this->line("  [{$branch->id}] {$branch->name}");
        }
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->line('Dry run — nothing created. Re-run without --dry-run to provision.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($missing as $branch) {
            $result = $provisioning->provision($branch);
            $rows[] = [
                $branch->id,
                $branch->name,
                $result['location']?->code ?? '—',
                $result['created'] ? 'created' : 'already had one',
            ];
        }

        $this->table(['Branch', 'Name', 'Location code', 'Result'], $rows);
        $this->info('Done. Stock still has to be transferred in before the branch can sell.');

        return self::SUCCESS;
    }
}
