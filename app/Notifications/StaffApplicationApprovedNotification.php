<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your account is ready."
 *
 * Sent to a new member of staff once their details have been checked and their
 * account created. It does not announce a hiring decision — that was made before
 * they were ever sent the form.
 *
 * A separate notification from StaffAccountCreatedNotification for one reason:
 * that one exists to deliver a password, and here there is none to deliver. They
 * chose their own on the joining form and the system holds only the hash.
 * Quoting a password back at them would mean either inventing one they do not
 * have or storing theirs in the clear, and both are worse than saying "the one
 * you chose".
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
     * First name only. "Hi Ama Mensah" reads like a form letter, and SMS is
     * charged by the character. Falls back to "Hi there" rather than "Hi ,"
     * if the name is somehow empty.
     */
    protected function greeting(object $notifiable): string
    {
        $first = trim((string) strtok(trim((string) $notifiable->name), ' '));

        return 'Hi '.($first === '' ? 'there' : $first).', ';
    }

    /**
     * Where they actually sign in. One entry point for every staff role — the
     * portal they land in is decided after login, not by the URL.
     *
     * Reads FRONTEND_URL, the same source the email button uses. If that is
     * unset the environment default (localhost:3000) goes out in the SMS, so
     * it must be set wherever this queue runs.
     */
    protected function loginUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/staff/login';
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
            ->subject('Welcome to CediBites — your account is ready')
            ->replyTo('support@cedibites.com', 'CediBites Support')
            ->view('emails.staff.application-approved', [
                'user' => $notifiable,
                'position' => $this->position(),
                'portalLabel' => $this->portalLabel(),
            ]);
    }

    /**
     * Straight quotes and plain punctuation only. A curly apostrophe or an em
     * dash drops the whole message out of GSM-7 into UCS-2, which cuts a
     * segment from 160 characters to 70 and triples what it costs to send.
     */
    public function toSms(object $notifiable): string
    {
        return "{$this->greeting($notifiable)}your CediBites account is ready. "
            ."You've been added as {$this->position()}. "
            ."Log in at {$this->loginUrl()} with your phone number and the password you created on the form.";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Your account is ready. You've been added as {$this->position()}. "
                .'Sign in with the password you chose on the form.',
        ];
    }
}
