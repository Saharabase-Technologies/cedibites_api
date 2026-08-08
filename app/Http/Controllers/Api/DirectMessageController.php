<?php

namespace App\Http\Controllers\Api;

use App\Helpers\PhoneHelper;
use App\Http\Controllers\Controller;
use App\Services\Campaigns\MessageMeter;
use App\Services\Contacts\PhoneNormaliser;
use App\Services\HubtelSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One text, to one number, now.
 *
 * Not a campaign of one. A campaign is a description of an audience that gets
 * re-resolved at send time, carries a permanent cost record, and is reported on
 * for months. This is somebody typing a number because a customer rang up about
 * an order — and forcing that through the campaign machinery would leave the
 * campaign list full of one-recipient entries that make the real reporting
 * unreadable.
 *
 * Deliberately exempt from two rails that guard campaigns, because both exist
 * for bulk and neither makes sense here:
 *
 *   Send window — the 08:00–19:00 rule stops 28,000 marketing texts landing at
 *   six in the morning. A single reply to a customer who is on the phone right
 *   now is not that, and refusing it until Monday would just push staff back to
 *   their own handsets, where nothing is recorded at all.
 *
 *   Seed mode — redirects every campaign recipient to a fixed test list. Applied
 *   here it would silently deliver the message to somebody other than the number
 *   that was typed, which is the one outcome this endpoint must never produce.
 *   The UI says plainly that this reaches the real number.
 *
 * What it is NOT exempt from: the `manage_campaigns` gate, the activity log, and
 * the delivery-attempt record that feeds SMS health.
 */
class DirectMessageController extends Controller
{
    public function __construct(
        private readonly HubtelSmsService $sms,
        private readonly MessageMeter $meter,
    ) {}

    /**
     * What this text will cost and how it will be billed, before it is sent.
     *
     * The same measurement the campaign composer uses, so a single text and a
     * blast cannot disagree about what a curly quote does to the price.
     */
    public function measure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1600'],
        ]);

        return response()->success([
            ...$this->meter->measure($validated['message']),
            'estimated_cost' => $this->meter->estimateCost($validated['message'], 1),
        ]);
    }

    /** Send it. Spends money the moment it is called. */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['required', 'string', 'min:1', 'max:1600'],
        ], [
            'phone.required' => 'Which number is this going to?',
            'message.required' => 'There is no message to send.',
            'message.max' => 'That is longer than ten text messages. Shorten it.',
        ]);

        // Normalised through the same reader the importer uses, so 0241234567,
        // +233241234567 and a number pasted out of WhatsApp all reach the same
        // handset — and anything that is not a Ghana mobile is refused here
        // rather than by Hubtel after we have been billed for the attempt.
        $phone = PhoneNormaliser::normalise($validated['phone']);

        if ($phone === null) {
            return response()->unprocessable(
                'That is not a Ghana mobile number. It should look like 0241234567.'
            );
        }

        $measurement = $this->meter->measure($validated['message']);

        try {
            // Hubtel wants 233XXXXXXXXX with no plus; everything on our side of
            // the line stores and displays +233XXXXXXXXX. The conversion belongs
            // at the boundary, not in the stored value.
            $this->sms->sendSingle(PhoneHelper::toInternational($phone), $validated['message'], 'direct_message');
        } catch (\Throwable $e) {
            // The attempt is already recorded by the service on every path out,
            // so a failure here is logged whether or not this controller is.
            activity('admin')
                ->causedBy($request->user())
                ->event('direct_message_failed')
                ->withProperties(['phone' => $phone, 'error' => $e->getMessage()])
                ->log('Direct SMS failed to '.$phone);

            return response()->unprocessable('That message could not be sent: '.$e->getMessage());
        }

        activity('admin')
            ->causedBy($request->user())
            ->event('direct_message_sent')
            ->withProperties([
                'phone' => $phone,
                // The text itself, because "who did we tell what" is the only
                // question anybody asks about a one-off message afterwards.
                'message' => $validated['message'],
                'segments' => $measurement['segments'],
                'cost' => $this->meter->estimateCost($validated['message'], 1),
            ])
            ->log('Direct SMS sent to '.$phone);

        return response()->success([
            'phone' => $phone,
            'segments' => $measurement['segments'],
            'estimated_cost' => $this->meter->estimateCost($validated['message'], 1),
        ], 'Sent to '.$phone.'.');
    }
}
