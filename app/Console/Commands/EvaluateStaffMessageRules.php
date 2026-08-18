<?php

namespace App\Console\Commands;

use App\Models\StaffMessageRule;
use App\Services\Platform\RuntimeSettings;
use App\Services\StaffMessaging\StaffRuleEvaluator;
use Illuminate\Console\Command;

class EvaluateStaffMessageRules extends Command
{
    protected $signature = 'messages:evaluate-rules {--rule= : Evaluate a single rule by id}';

    protected $description = 'Run the staff message rules and send anything they turn up';

    public function handle(StaffRuleEvaluator $evaluator): int
    {
        $rule = null;

        if ($id = $this->option('rule')) {
            $rule = StaffMessageRule::find($id);

            if (! $rule) {
                $this->error("No rule with id {$id}.");

                return self::FAILURE;
            }
        }

        $totals = $evaluator->run($rule);

        // Held back is reported beside sent, always. A run that matched 300 and
        // sent 4 is the guardrails working; printing only "sent 4" makes it look
        // broken and somebody switches the feature off.
        $this->line(sprintf(
            'matched %d · sent %d · held back %d',
            $totals['matched'],
            $totals['sent'],
            $totals['held_back'],
        ));

        if (! app(RuntimeSettings::class)->get('staff_messaging.automation_enabled')) {
            $this->warn('Automation is OFF — everything above was recorded, nothing was sent.');
        }

        return self::SUCCESS;
    }
}
