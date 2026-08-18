<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignDeliveryPoller;
use Illuminate\Console\Command;

/**
 * Ask Hubtel what each recent campaign actually delivered, and what it charged.
 *
 * Runs on a schedule rather than at send time because delivery is not instant —
 * a message accepted at 11:00 may not be marked delivered until 11:05, and some
 * never are.
 */
class PollCampaignDeliveries extends Command
{
    protected $signature = 'campaigns:poll-deliveries {--hours=48 : How far back to look}';

    protected $description = 'Read delivery status and actual cost for recently sent campaigns';

    public function handle(CampaignDeliveryPoller $poller): int
    {
        $hours = (int) $this->option('hours');
        $updated = $poller->pollRecent($hours);

        $this->info("Updated {$updated} campaign(s) from the last {$hours} hours.");

        return self::SUCCESS;
    }
}
