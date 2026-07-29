<?php

namespace App\Http\Middleware;

use App\Enums\CustomerStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerActive
{
    /**
     * Reject requests from suspended customers.
     *
     * Suspension is enforced regardless of `is_guest`. It used to be skipped for
     * guests, which quietly meant suspending anyone who had ever ordered as a
     * guest did nothing at all — and since a guest row is created by the first
     * order, that covered most of the customer base. `is_guest` describes how the
     * account came into being; it was never a statement about whether the
     * account's suspension counts.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $customer = $user->customer;

        if ($customer && $customer->status === CustomerStatus::Suspended) {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'error' => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
