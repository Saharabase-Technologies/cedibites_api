<?php

namespace App\Console\Commands;

use App\Services\Feedback\FeedbackService;
use Illuminate\Console\Command;

/**
 * Purge request logs older than the retention window. Beta volume is trivial;
 * the point is that request_logs is disposable, never an audit log.
 */
class PurgeRequestLogs extends Command
{
    protected $signature = 'feedback:purge-request-logs';

    protected $description = 'Purge request logs older than the configured retention window';

    public function handle(FeedbackService $service): int
    {
        $days = (int) config('feedback.request_log_retention_days', 14);
        $deleted = $service->purgeExpiredRequestLogs($days);

        $this->info("Purged {$deleted} request log row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
