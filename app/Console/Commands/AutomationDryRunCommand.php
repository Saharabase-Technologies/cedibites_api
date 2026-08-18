<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Services\Automation\AutomationDryRun;
use Illuminate\Console\Command;

/**
 * Point a rule at real history and report what it would have done.
 *
 * Sends nothing, writes nothing. Run this before switching any rule on — it is
 * the only thing that catches a rule which fires on every order, and it catches
 * it for free rather than for the price of four thousand texts.
 */
class AutomationDryRunCommand extends Command
{
    protected $signature = 'automation:dry-run {rule : Rule id} {--days= : How far back to replay}';

    protected $description = 'Replay an automation rule against real history without sending anything';

    public function handle(AutomationDryRun $dryRun): int
    {
        $rule = AutomationRule::find($this->argument('rule'));

        if (! $rule) {
            $this->error('No rule with that id.');

            return self::FAILURE;
        }

        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        $this->info("Replaying \"{$rule->name}\" ({$rule->event->label()})…");
        $result = $dryRun->run($rule, $days);

        $this->newLine();
        $this->line("  Window                 {$result['days']} days");
        $this->line('  Orders examined        '.number_format($result['orders_examined']));
        $this->line('  Matched the rule       '.number_format($result['matched']));
        $this->line('  <options=bold>Would have sent        '.number_format($result['would_send']).'</>');
        $this->line('  People reached         '.number_format($result['people_reached']));

        // The number that says whether the cooldown is set right. A rule
        // reaching 3 people 47 times is a different animal from one reaching
        // 300 people 47 times, and the totals cannot tell them apart.
        $this->line("  Most to one person     {$result['busiest_recipient']}");

        $this->line('  Cost                   GHS '.number_format($result['estimated_cost'], 4)
            ." ({$result['segments_per_message']} text(s) each)");

        if ($result['suppressed'] !== []) {
            $this->newLine();
            $this->line('  Held back:');

            foreach ($result['suppressed'] as $reason => $count) {
                $this->line('    '.str_pad($reason, 20).number_format($count));
            }
        }

        if ($result['sample'] !== []) {
            $this->newLine();
            $this->line('  First few it would have reached:');

            foreach ($result['sample'] as $row) {
                $this->line('    '.str_pad($row['phone'], 16).($row['name'] ?? '—'));
            }
        }

        $this->newLine();
        $this->comment('Nothing was sent. This figure ignores other rules, so the real number will be lower.');

        return self::SUCCESS;
    }
}
