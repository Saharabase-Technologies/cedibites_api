<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffAccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public $timeout = 30;

    public function __construct(
        public string $temporaryPassword,
        /** Role value (e.g. "branch_partner") — tailors the welcome copy & portal wording. */
        public ?string $role = null,
    ) {}

    /** Friendly role name, e.g. "Branch Partner" (null when no role given). */
    protected function roleLabel(): ?string
    {
        return $this->role ? Role::tryFrom($this->role)?->label() : null;
    }

    /** Portal the recipient lands in, e.g. "Partner Portal". */
    protected function portalLabel(): string
    {
        return ($this->role ? Role::tryFrom($this->role)?->portalLabel() : null) ?? 'Staff Portal';
    }

    /**
     * Get the notification's delivery channels.
     *
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

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = $this->roleLabel();
        $subject = $roleLabel
            ? "Welcome to CediBites — You've been added as a {$roleLabel}"
            : 'Your CediBites Staff Account Has Been Created';

        return (new MailMessage)
            ->subject($subject)
            ->replyTo('support@cedibites.com', 'CediBites Support')
            ->view('emails.staff.account-created', [
                'user' => $notifiable,
                'temporaryPassword' => $this->temporaryPassword,
                'roleLabel' => $roleLabel,
                'portalLabel' => $this->portalLabel(),
            ]);
    }

    /**
     * Get the SMS representation of the notification.
     */
    public function toSms(object $notifiable): string
    {
        $roleLabel = $this->roleLabel();
        $intro = $roleLabel
            ? "You've been added as a {$roleLabel} on CediBites."
            : 'Your CediBites staff account has been created.';

        return "CediBites: {$intro} Use this temporary password to log in: {$this->temporaryPassword}. You can change it after logging in.";
    }

    /**
     * Get the array representation of the notification (database).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'Your CediBites staff account has been created. Check your email or SMS for your temporary password.',
        ];
    }
}
