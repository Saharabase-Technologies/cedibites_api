<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You got the job, and your account is ready."
 *
 * A separate notification from StaffAccountCreatedNotification for one reason:
 * that one exists to deliver a password, and here there is none to deliver. The
 * applicant chose their own on the recruitment form and the system holds only
 * the hash. Quoting a password back at them would mean either inventing one they
 * do not have or storing theirs in the clear, and both are worse than saying
 * "the one you chose".
 */
class StaffApplicationApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public $timeout = 30;

    public function __construct(
        /** Role value (e.g. "sales_staff") — tailors the welcome copy & portal wording. */
        public ?string $role = null,
        /** Branch name, or null for a company-wide role such as the call centre. */
        public ?string $postingName = null,
    ) {}

    protected function roleLabel(): ?string
    {
        return $this->role ? Role::tryFrom($this->role)?->label() : null;
    }

    protected function portalLabel(): string
    {
        return ($this->role ? Role::tryFrom($this->role)?->portalLabel() : null) ?? 'Staff Portal';
    }

    /** "Sales Staff at Lakeside", or just "Sales Staff" when there is no branch. */
    protected function position(): string
    {
        $role = $this->roleLabel() ?? 'a member of staff';

        return $this->postingName ? "{$role} at {$this->postingName}" : $role;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->phone) {
            $channels[] = SmsChannel::class;
        }

        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to CediBites — your application has been approved')
            ->replyTo('support@cedibites.com', 'CediBites Support')
            ->view('emails.staff.application-approved', [
                'user' => $notifiable,
                'position' => $this->position(),
                'portalLabel' => $this->portalLabel(),
            ]);
    }

    public function toSms(object $notifiable): string
    {
        return "CediBites: Your application has been approved and you've been added as {$this->position()}. "
            .'Log in with your phone number and the password you chose when you applied.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Your application has been approved. You've been added as {$this->position()}. "
                .'Sign in with the password you chose when you applied.',
        ];
    }
}
