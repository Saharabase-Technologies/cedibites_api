<?php

namespace App\Console\Commands;

use App\Services\Platform\TechErrorTexter;
use Illuminate\Console\Command;

/**
 * Texts the tech admin about new faults. See TechErrorTexter for what counts,
 * what is left out, and how repeats and the daily limit work.
 */
class TextTechErrors extends Command
{
    protected $signature = 'alerts:text-tech-errors
                            {--dry : Show what would be texted and send nothing, even while texts are switched off}';

    protected $description = 'Text the tech admin about new faults on the error feed';

    public function handle(TechErrorTexter $texter): int
    {
        $report = $texter->run((bool) $this->option('dry'));

        if (! $report['enabled'] && ! $this->option('dry')) {
            $this->line('Error texts are switched off. Turn them on in the platform settings panel.');

            return self::SUCCESS;
        }

        $this->line("recipients: {$report['recipients']}");

        if ($report['faults'] === []) {
            $this->line('nothing new');

            return self::SUCCESS;
        }

        foreach ($report['faults'] as $fault) {
            $this->line(sprintf('  %-15s %s', $fault['outcome'], $fault['title']));

            if ($this->option('dry') && $fault['message']) {
                $this->line('                  '.$fault['message']);
            }
        }

        return self::SUCCESS;
    }
}
