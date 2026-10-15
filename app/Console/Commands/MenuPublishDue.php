<?php

namespace App\Console\Commands;

use App\Enums\MenuPublicationStatus;
use App\Models\MenuPublication;
use App\Services\Menu\MenuPublisher;
use Illuminate\Console\Command;

/**
 * Puts a scheduled publish live once its time has come.
 *
 * Runs every minute, so 06:00 means by 06:01. It runs under the PM2 process
 * `cedibites-scheduler` on prod; if that process is down, nothing scheduled
 * goes live, and "Go live now" on the page still works.
 */
class MenuPublishDue extends Command
{
    protected $signature = 'menu:publish-due';

    protected $description = 'Put a scheduled menu publish live once its time has come';

    public function handle(MenuPublisher $publisher): int
    {
        $due = MenuPublication::query()
            ->where('status', MenuPublicationStatus::Scheduled->value)
            ->where('publish_at', '<=', now())
            ->orderBy('publish_at')
            ->get();

        foreach ($due as $publication) {
            $result = $publisher->publishScheduled($publication);

            $this->info(match ($result->status) {
                MenuPublicationStatus::Published => "Publish {$result->id} is live: {$result->change_count} changes.",
                default => "Publish {$result->id} did not go live: ".($result->note ?? $result->status->value),
            });
        }

        return self::SUCCESS;
    }
}
