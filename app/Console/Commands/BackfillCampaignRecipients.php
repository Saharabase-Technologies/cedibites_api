<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignLedger;
use Illuminate\Console\Command;

/**
 * Rebuild the recipient list of a campaign that went out before the list was
 * kept, so the people it missed can be sent to.
 *
 * Written for "New Month" (2026-10-01), which reached 39 of 3,539 because the
 * Hubtel account ran short of credit. Its only record of who was missed is
 * `sms_delivery_attempts`, which is cleared after thirty days. Run this before
 * then or the list is gone.
 *
 * Sends nothing. It writes the list and corrects the status, and the resend is
 * still a button somebody has to press.
 */
class BackfillCampaignRecipients extends Command
{
    protected $signature = 'campaigns:backfill-recipients
                            {campaign : The campaign id}
                            {--dry : Report what would be written and change nothing}';

    protected $description = 'Rebuild a past campaign\'s recipient list from the SMS attempt log, so it can be resumed';

    public function handle(CampaignLedger $ledger): int
    {
        $campaign = Campaign::find($this->argument('campaign'));

        if (! $campaign) {
            $this->error('No campaign with that id.');

            return self::FAILURE;
        }

        if (! $campaign->status->hasStarted()) {
            $this->error('That campaign has not been sent. There is nothing to rebuild.');

            return self::FAILURE;
        }

        if ($ledger->hasRows($campaign->id)) {
            $this->info('That campaign already has its list. Nothing to do.');

            return self::SUCCESS;
        }

        $attempts = $campaign->deliveryAttempts()->count();

        if ($attempts === 0) {
            $this->error('The attempt log holds nothing for that campaign. It has been cleared, and the list cannot be rebuilt.');

            return self::FAILURE;
        }

        $this->line("\"{$campaign->name}\": {$attempts} attempt rows, {$campaign->recipient_count} recipients on the campaign.");

        if ($this->option('dry')) {
            $this->line('Dry run. Nothing written.');

            return self::SUCCESS;
        }

        $tally = $ledger->backfillFromAttempts($campaign);

        $this->table(
            ['written', 'accepted', 'refused', 'no answer', 'not mobile numbers'],
            [[$tally['written'], $tally['accepted'], $tally['refused'], $tally['unsure'], $tally['not_mobile']]],
        );

        // "Sent" on a campaign that left people behind was the old rule. Put
        // it right, quietly: this is a correction to the record, not an edit
        // somebody made to the campaign.
        if ($campaign->status === CampaignStatus::Sent && $campaign->sent_count < $campaign->recipient_count) {
            $campaign->forceFill(['status' => CampaignStatus::PartlySent])->saveQuietly();
            $this->line('Status corrected from Sent to Partly sent.');
        }

        $this->info($ledger->resumableCount($campaign->id).' people can now be sent to from the campaign page.');

        return self::SUCCESS;
    }
}
