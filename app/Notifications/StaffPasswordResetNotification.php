<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(
        public string $resetLink,
        public ?string $otp = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [SmsChannel::class];

        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Reset your CediBites password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your CediBites staff portal password.');

        if ($this->otp) {
            $mail->line('Your verification code is:')
                ->line('**'.$this->otp.'**')
                ->line('This code expires in 15 minutes.')
                ->line('Or use the button below to reset your password directly:');
        }

        return $mail
            ->action('Reset Password', $this->resetLink)
            ->line('This link expires in 60 minutes.')
            ->line('If you did not request a password reset, you can ignore this email.')
            ->salutation('Thank you, The CediBites Team');
    }

    public function toSms(object $notifiable): string
    {
        if ($this->otp) {
            return "CediBites: Your password reset code is {$this->otp} (valid 15 min). Or reset here: {$this->resetLink}. Ignore if you didn't request this.";
        }

        return "CediBites: Reset your password here: {$this->resetLink}. Link expires in 60 minutes. Ignore if you didn't request this.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
