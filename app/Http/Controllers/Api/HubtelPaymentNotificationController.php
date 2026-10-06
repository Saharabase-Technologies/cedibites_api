<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyHubtelPaymentNotification;
use App\Models\Branch;
use App\Models\HubtelPaymentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Hubtel's payment notifications for one branch's Collection Account.
 *
 * Set on the Merchant Dashboard under Settings, Logs, Notifications, Payment
 * Notifications Configuration, one URL per branch:
 * /v1/payments/hubtel/notifications/{collection account number}.
 *
 * The post is kept whole and then checked. It has no signature, so nothing
 * trusts it: VerifyHubtelPaymentNotification asks Hubtel's status check about
 * the payment, and only one Hubtel calls Paid reaches the till's list. A
 * forged post comes back "not found" and stops there.
 */
class HubtelPaymentNotificationController extends Controller
{
    /** Headers never kept, even though Hubtel has no reason to send them. */
    private const DROPPED_HEADERS = ['authorization', 'cookie', 'php-auth-user', 'php-auth-pw'];

    public function __invoke(Request $request, string $account): JsonResponse
    {
        $headers = collect($request->headers->all())
            ->except(self::DROPPED_HEADERS)
            ->map(fn (array $values) => implode(', ', $values))
            ->all();

        $notification = HubtelPaymentNotification::create([
            'account_number' => $account,
            'branch_id' => Branch::where('hubtel_account_number', $account)->value('id'),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'payload' => $request->all() ?: null,
            'raw_body' => Str::limit($request->getContent(), 65000, '') ?: null,
            'headers' => $headers,
        ]);

        // The payload carries customers' phone numbers, so the log line does not.
        Log::info('Hubtel payment notification received', [
            'id' => $notification->id,
            'account_number' => $account,
            'ip' => $notification->ip,
        ]);

        if ($notification->payload !== null) {
            VerifyHubtelPaymentNotification::dispatch($notification->id);
        }

        return response()->success(null, 'Notification received');
    }
}
