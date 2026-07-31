<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the tech admin that SMS is failing — or has recovered.
 *
 * Deliberately never uses SmsChannel. An alert about a dead SMS pipe cannot be
 * delivered down the dead SMS pipe; routing it there would guarantee the one
 * message that must arrive is the one that cannot. Mail plus the in-app feed
 * only. Do not add SmsChannel to via() no matter how convenient it looks.
 */
class SmsHealthAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $health  Output of SmsHealthService::check()
     */
    public function __construct(
        public array $health,
        public bool $recovered = false,
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
        if ($this->recovered) {
            return (new MailMessage)
                ->subject('SMS is working again — CediBites')
                ->greeting('SMS recovered')
                ->line('Messages are being delivered again.')
                ->line('Last success: '.($this->health['last_success_at'] ?? 'just now'))
                ->salutation('CediBites platform monitoring');
        }

        $mail = (new MailMessage)
            ->error()
            ->subject('['.mb_strtoupper((string) $this->health['status']).'] SMS delivery is failing — CediBites')
            ->greeting('SMS delivery is failing')
            ->line($this->health['reason_label'] ?? 'SMS sends are being rejected.')
            ->line('**What to do:** '.($this->health['remedy'] ?? 'Check the platform health page.'));

        $window = $this->health['window_hours'] ?? 24;
        $mail->line(sprintf(
            'In the last %dh: %d failed, %d sent (%s%% failure rate). %d consecutive failures.',
            $window,
            $this->health['failed'] ?? 0,
            $this->health['sent'] ?? 0,
            $this->health['failure_rate'] ?? 0,
            $this->health['consecutive_failures'] ?? 0,
        ));

        $mail->line('Last successful message: '.($this->health['last_success_at'] ?? 'none on record'));

        if (! empty($this->health['affected'])) {
            $mail->line('**Messages being lost:**');
            foreach (array_slice($this->health['affected'], 0, 6) as $row) {
                $mail->line("- {$row['notification']} — {$row['failures']} failed");
            }
        }

        return $mail
            ->action('Open platform health', rtrim((string) config('app.frontend_url'), '/').'/admin/platform')
            ->salutation('CediBites platform monitoring');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sms_health',
            'status' => $this->recovered ? 'recovered' : $this->health['status'],
            'recovered' => $this->recovered,
            'reason' => $this->health['reason'] ?? null,
            'title' => $this->recovered
                ? 'SMS is working again'
                : ($this->health['reason_label'] ?? 'SMS delivery is failing'),
            'message' => $this->recovered
                ? 'SMS messages are being delivered again.'
                : sprintf(
                    '%d SMS failed in the last %dh (%s%% failure rate). %s',
                    $this->health['failed'] ?? 0,
                    $this->health['window_hours'] ?? 24,
                    $this->health['failure_rate'] ?? 0,
                    $this->health['remedy'] ?? '',
                ),
            'remedy' => $this->health['remedy'] ?? null,
            'affected' => $this->health['affected'] ?? [],
        ];
    }
}
