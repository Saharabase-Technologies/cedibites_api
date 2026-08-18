<?php

namespace App\Console\Commands;

use App\Models\StaffMessageRule;
use App\Services\StaffMessaging\StaffRuleDryRun;
use Illuminate\Console\Command;

class DryRunStaffMessageRule extends Command
{
    protected $signature = 'messages:dry-run {rule : Rule id} {--days=30 : How far back to replay}';

    protected $description = 'Replay a staff message rule against real history without sending anything';

    public function handle(StaffRuleDryRun $dryRun): int
    {
        $rule = StaffMessageRule::find($this->argument('rule'));

        if (! $rule) {
            $this->error('No rule with that id.');

            return self::FAILURE;
        }

        $result = $dryRun->run($rule, (int) $this->option('days'));

        $this->info($result['rule']." — {$result['event']}, last {$result['days']} days");
        $this->newLine();

        $this->table(['', ''], [
            ['Matched', $result['matched']],
            ['Would send', $result['would_send']],
            ['Held back by cooldown', $result['held_back']],
            ['People reached', $result['people_reached']],
            ['Busiest one person', $result['busiest_recipient']],
        ]);

        // The figure that decides whether this rule is safe. A rule reaching
        // three people forty times and one reaching three hundred people forty
        // times are indistinguishable in the totals above.
        if ($result['busiest_recipient'] >= 4) {
            $this->warn(sprintf(
                'One person would have received %d messages in this window. Consider a longer cooldown.',
                $result['busiest_recipient'],
            ));
        }

        if ($result['samples'] !== []) {
            $this->newLine();
            $this->line('Sample messages:');

            foreach ($result['samples'] as $sample) {
                $this->line("  → {$sample['to']}: {$sample['body']}");
            }
        }

        $this->newLine();
        $this->comment('This is a ceiling: the hourly cap and other rules competing for the same order are not modelled.');

        return self::SUCCESS;
    }
}
