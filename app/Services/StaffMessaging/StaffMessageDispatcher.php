<?php

namespace App\Services\StaffMessaging;

use App\Events\StaffMessageEvent;
use App\Jobs\EscalateStaffMessageToSms;
use App\Models\StaffMessage;
use App\Models\StaffMessageRecipient;
use App\Models\User;
use App\Notifications\StaffMessageNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes a drafted message and actually puts it in front of people.
 *
 * The three delivery routes are a ladder, not a broadcast to all three at once:
 * realtime if the tab is open, push if the phone is closed, SMS only if it is
 * still unread when the escalation window runs out. Firing all three
 * simultaneously would mean paying Hubtel to tell somebody about a message
 * already open on the screen in front of them.
 */
class StaffMessageDispatcher
{
    /**
     * Stamp a draft as sent and fan it out.
     *
     * Recipient rows are written inside a transaction and delivery happens after
     * it commits. Broadcasting from inside the transaction is the classic
     * ordering bug: the websocket arrives, the browser refetches, and the row is
     * not visible yet, so the bell shows a message the inbox insists does not
     * exist.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function send(StaffMessage $message, Collection $recipients): StaffMessage
    {
        $recipients = $recipients->unique('id')->values();

        if ($recipients->isEmpty()) {
            // Not an exception. A rule whose audience emptied out — everyone
            // matching left or was suspended — is an ordinary Tuesday, and
            // throwing here would fail the whole evaluation run over it.
            $message->forceFill([
                'sent_at' => $message->sent_at ?? now(),
                'recipient_count' => 0,
            ])->save();

            return $message;
        }

        $rows = DB::transaction(function () use ($message, $recipients) {
            $message->forceFill([
                'sent_at' => $message->sent_at ?? now(),
                'recipient_count' => $recipients->count(),
            ])->save();

            return $recipients->map(function (User $user) use ($message) {
                return StaffMessageRecipient::firstOrCreate(
                    ['staff_message_id' => $message->id, 'user_id' => $user->id],
                    ['branch_id' => $this->branchIdFor($user)],
                );
            });
        });

        $rows->each(fn (StaffMessageRecipient $row) => $this->deliver($row));

        return $message->refresh();
    }

    /**
     * Realtime, then push, then schedule the SMS escalation.
     *
     * Each route is wrapped separately. Reverb being down, or one person's push
     * subscription having expired, must not stop the other thirty-nine
     * deliveries — and must not stop the SMS ladder either, since a failed push
     * is exactly when the fallback matters most.
     */
    public function deliver(StaffMessageRecipient $recipient): void
    {
        $recipient->loadMissing('message.sender', 'user');

        try {
            StaffMessageEvent::dispatch($recipient);
        } catch (\Throwable $e) {
            Log::warning('Staff message realtime broadcast failed', [
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $recipient->user?->notify(new StaffMessageNotification($recipient));
        } catch (\Throwable $e) {
            Log::warning('Staff message push failed', [
                'recipient_id' => $recipient->id,
                'error' => $e->getMessage(),
            ]);
        }

        $recipient->forceFill(['delivered_at' => $recipient->delivered_at ?? now()])->save();

        $this->scheduleSmsEscalation($recipient);
    }

    /**
     * Queue the SMS fallback, if this message asked for one.
     *
     * The job re-reads `read_at` when it runs, so queuing it is not a decision to
     * send — it is a decision to check later. That is why it can be queued
     * unconditionally here rather than guessing now whether the message will have
     * been read by then.
     */
    private function scheduleSmsEscalation(StaffMessageRecipient $recipient): void
    {
        $minutes = $recipient->message->sms_fallback_after_minutes;

        if ($minutes === null) {
            return;
        }

        EscalateStaffMessageToSms::dispatch($recipient->id)
            ->delay(now()->addMinutes(max(0, $minutes)));
    }

    /**
     * The branch this person held at the moment the message went out.
     *
     * Company-wide roles legitimately have none, and null here means exactly
     * that rather than "unknown".
     */
    private function branchIdFor(User $user): ?int
    {
        return $user->employee?->branches->first()?->id;
    }
}
