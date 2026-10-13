<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\VerifyHubtelUssdFulfilment;
use App\Models\HubtelUssdFulfilment;
use App\Services\Payments\UssdPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The two URLs Hubtel's Programmable Services calls for our USSD service.
 *
 * Set on the developer portal under Programmable Services, Manage Services:
 * the Service Interaction URL is /v1/payments/hubtel/ussd and the Service
 * Fulfilment URL is /v1/payments/hubtel/ussd/fulfilment. The service is then
 * attached to a USSD code on the Merchant Dashboard.
 */
class HubtelUssdController extends Controller
{
    /** Headers never kept, even though Hubtel has no reason to send them. */
    private const DROPPED_HEADERS = ['authorization', 'cookie', 'php-auth-user', 'php-auth-pw'];

    /**
     * Every dial and every key press. The answer is the next screen, in
     * Hubtel's own shape and not ours: anything else shows the customer
     * Hubtel's "response from the provider is invalid". So this never
     * answers with an error, whatever went wrong inside.
     */
    public function interaction(Request $request, UssdPayments $payments): JsonResponse
    {
        $post = $request->all();

        try {
            $answer = $payments->answer($post);
        } catch (\Throwable $e) {
            report($e);

            $sessionId = $post['SessionId'] ?? '';
            $answer = $payments->apology(is_scalar($sessionId) ? (string) $sessionId : '');
        }

        return response()->json($answer);
    }

    /**
     * Hubtel's word that a dial has been paid. Kept whole, then checked.
     */
    public function fulfilment(Request $request): JsonResponse
    {
        $headers = collect($request->headers->all())
            ->except(self::DROPPED_HEADERS)
            ->map(fn (array $values) => implode(', ', $values))
            ->all();

        $sessionId = $request->input('SessionId');

        $fulfilment = HubtelUssdFulfilment::create([
            'session_id' => is_scalar($sessionId) ? Str::limit((string) $sessionId, 64, '') : null,
            'ip' => $request->ip(),
            'payload' => $request->all() ?: null,
            'raw_body' => Str::limit($request->getContent(), 65000, '') ?: null,
            'headers' => $headers,
        ]);

        // The payload carries the customer's name and number, so the log line does not.
        Log::info('Hubtel USSD fulfilment received', [
            'id' => $fulfilment->id,
            'ip' => $fulfilment->ip,
        ]);

        if ($fulfilment->payload !== null) {
            VerifyHubtelUssdFulfilment::dispatch($fulfilment->id);
        }

        return response()->success(null, 'Fulfilment received');
    }
}
