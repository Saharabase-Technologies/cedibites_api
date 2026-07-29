<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require a token minted by the staff login before any staff surface opens.
 *
 * One human can be both a staff member and a customer — the same person orders
 * lunch from the phone they also run the warehouse with — so `users` carries one
 * identity and `employees` / `customers` hang off it. That is the right model,
 * but it means a permission check alone asks the wrong question: it asks who the
 * user is, when the question is which door they came through.
 *
 * Without this gate, an OTP to a staff member's phone returns a token carrying
 * that staff member's full permission set, bypassing the password, the
 * EmployeeStatus::Active check and the must_reset_password gate that
 * EmployeeAuthController::login enforces. A SIM swap, a phone left on a counter,
 * or a dismissed employee whose number still works would all be enough.
 *
 * The check is deliberately strict: a legacy token holding the `*` wildcard is
 * rejected rather than honoured, because every pre-existing customer token also
 * holds `*`. Sanctum tokens already expire every 24h (config/sanctum.php), so
 * the cost of that strictness is one extra sign-in, once.
 */
class EnsureStaffToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // The rule is about bearer tokens, because a bearer token is the only
        // thing any login here mints. Authenticating some other way leaves either
        // a TransientToken (the session-guard fallback) or nothing at all (a user
        // set directly, as actingAs() does in tests) — neither is reachable
        // through the customer OTP flow this guards against, so neither is
        // treated as a way past it.
        if ($token === null || $token instanceof TransientToken) {
            return $next($request);
        }

        if (! in_array('staff', $token->abilities ?? [], true)) {
            return response()->json([
                'message' => 'Staff sign-in required. Please sign in through the staff portal.',
                'error' => 'staff_token_required',
            ], 403);
        }

        return $next($request);
    }
}
