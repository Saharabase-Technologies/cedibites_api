<?php

namespace App\Notifications;

use App\Enums\SmsFailureReason;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A campaign stopped part way and people are still waiting on it.
 *
 * Mail and the in-app feed only, never SmsChannel. The commonest reason a
 * campaign pauses is that the SMS account is out of credit, and a text about
 * that is the one message that cannot be trusted to arrive. Same rule as
 * SmsHealthAlertNotification.
 */
class CampaignPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(
        public Campaign $campaign,
        public SmsFailureReason $reason,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        // $notifiable may be an on-demand mail route (no email attribute).
        if (! ($notifiable instanceof \App\Models\User) || $notifiable->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $figures = $this->figures();

        return (new MailMessage)
            ->error()
            ->subject('CediBites: campaign "'.$this->campaign->name.'" stopped at '.$figures['sent'].' of '.$figures['total'])
            ->greeting('A campaign has stopped part way')
            ->line('"'.$this->campaign->name.'" reached '.$figures['sent'].' of '.$figures['total'].' people. '.$figures['waiting'].' are still waiting and have not been sent to.')
            ->line('**Why:** '.$this->reason->label().'.')
            ->line('**What to do:** '.$this->reason->remedy())
            ->line('Nobody has been texted twice and nothing has been lost. When it is put right, open the campaign and press "Send to the rest".')
            ->action('Open the campaign', $this->url())
            ->salutation('CediBites platform monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $figures = $this->figures();

        return [
            'type' => 'campaign_paused',
            'title' => 'Campaign stopped at '.$figures['sent'].' of '.$figures['total'],
            'message' => '"'.$this->campaign->name.'": '.$this->reason->label().'. '.$figures['waiting'].' people are still waiting.',
            'campaign_id' => $this->campaign->id,
            'reason' => $this->reason->value,
            'remedy' => $this->reason->remedy(),
            'url' => $this->url(),
        ];
    }

    /**
     * @return array{sent: string, total: string, waiting: string}
     */
    private function figures(): array
    {
        $sent = (int) $this->campaign->sent_count;
        $total = (int) $this->campaign->recipient_count;

        return [
            'sent' => number_format($sent),
            'total' => number_format($total),
            'waiting' => number_format(max(0, $total - $this->campaign->accountedFor())),
        ];
    }

    private function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/admin/campaigns/'.$this->campaign->id;
    }
}
