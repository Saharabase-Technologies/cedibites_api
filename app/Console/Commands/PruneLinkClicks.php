<?php

namespace App\Console\Commands;

use App\Services\ShortLinkService;
use Illuminate\Console\Command;

/**
 * Trim the click timeline. The per-link total is not touched — see
 * ShortLinkService::pruneClicks() for why that distinction matters.
 */
class PruneLinkClicks extends Command
{
    protected $signature = 'links:prune-clicks';

    protected $description = 'Delete individual link click rows older than the retention window';

    public function handle(ShortLinkService $links): int
    {
        $days = (int) config('short_links.click_retention_days', 180);
        $deleted = $links->pruneClicks($days);

        $this->info("Pruned {$deleted} link click row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
