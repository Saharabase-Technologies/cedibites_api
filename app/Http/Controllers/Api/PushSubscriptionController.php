<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Store or update a push subscription for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aesgcm',
        );

        return response()->json(['message' => 'Push subscription saved.'], 201);
    }

    /**
     * Remove a push subscription.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'Push subscription removed.']);
    }

    /**
     * Return the VAPID public key so the frontend can subscribe.
     */
    public function vapidPublicKey(): JsonResponse
    {
        return response()->json([
            'data' => [
                'public_key' => config('webpush.vapid.public_key'),
            ],
        ]);
    }

    /**
     * POST /orders/{orderNumber}/push-subscribe - turn on updates for one order.
     *
     * Every other route on this controller needs `auth:sanctum`, which is why
     * push has only ever worked for staff: most orders are placed by people who
     * never sign in, so the customer half could not fetch a VAPID key, let
     * alone register a subscription.
     *
     * The tracking token stands in for a login. Whoever holds the link we
     * texted owns the order, so they may ask to be told when it moves. The
     * subscription attaches to the user the order already resolved to by phone,
     * which is the same account they would land on if they ever did sign in.
     */
    public function subscribeToOrder(Request $request, string $orderNumber): JsonResponse
    {
        $validated = $request->validate([
            't' => ['required', 'string'],
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string'],
        ]);

        $order = \App\Models\Order::where('order_number', $orderNumber)->first();

        // A wrong token and a missing order answer the same way. Telling them
        // apart turns this into a test for whether an order number is real.
        if (! $order || ! hash_equals($order->trackingToken(), $validated['t'])) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $user = $order->customer?->user;
        if (! $user) {
            return response()->json(['message' => 'This order has nobody to notify.'], 422);
        }

        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['content_encoding'] ?? 'aesgcm',
        );

        return response()->json(['message' => 'You will be told when this order moves.'], 201);
    }

    /**
     * GET /push/public-key - the VAPID key, without a login.
     *
     * It is a public key. It is in every browser that has ever subscribed and
     * in the JavaScript bundle either way, so gating it behind auth protected
     * nothing and stopped customers subscribing at all.
     */
    public function publicVapidKey(): JsonResponse
    {
        return response()->json(['public_key' => config('webpush.vapid.public_key')]);
    }
}
