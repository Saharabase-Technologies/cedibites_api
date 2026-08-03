<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public $timeout = 30;

    public function __construct(
        public Order $order
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', SmsChannel::class];

        // Add email if user has email address
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
        return (new MailMessage)
            ->subject("Order #{$this->order->order_number} Confirmed")
            ->view('emails.orders.confirmed', ['order' => $this->order]);
    }

    /**
     * Get the SMS representation of the notification.
     *
     * The estimate is stamped at creation by PrepTimeEstimator, so it is
     * normally present. The clause is still conditional because it used to be
     * interpolated unguarded — `estimated_prep_time` was null on every order
     * ever created, and PHP renders null as nothing, so every confirmation for
     * months went out reading "Estimated time:  mins." with a hole in it. A
     * sentence that can render empty should not be built by concatenation.
     */
    public function toSms(object $notifiable): string
    {
        $message = "CediBites: Order #{$this->order->order_number} confirmed! ".
                   'Total: GHS '.$this->formattedTotal().'.';

        if ($this->order->estimated_prep_time) {
            $message .= " Estimated time: {$this->order->estimated_prep_time} mins.";
        }

        return $message;
    }

    /**
     * Thousands-separated, always two decimals. `total_amount` is a
     * `decimal:2` cast, so interpolating it raw printed a large order as
     * "GHS 1234.50".
     */
    protected function formattedTotal(): string
    {
        return number_format((float) $this->order->total_amount, 2);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'total_amount' => $this->order->total_amount,
            'estimated_time' => $this->order->estimated_prep_time,
            'message' => "Your order #{$this->order->order_number} has been confirmed!",
        ];
    }
}
