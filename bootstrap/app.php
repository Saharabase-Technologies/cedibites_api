<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Stop the auth middleware reaching for a sign-in page that does not exist.
         *
         * Laravel defaults this to `fn () => route('login')` (see
         * ApplicationBuilder::withMiddleware). This app is API-only and defines
         * no `login` route, so on every request without a valid token the
         * middleware threw RouteNotFoundException — "Route [login] not defined"
         * — while *building* the AuthenticationException. The result was a 500
         * for what is simply an expired session, and it never reached the
         * exception handler as an auth failure at all.
         *
         * Returning null leaves the exception as a plain AuthenticationException,
         * which the handler below turns into a 401. Both halves are required:
         * without this the handler is unreachable, and without the handler this
         * still ends at `?? route('login')` inside Handler::unauthenticated.
         */
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \App\Http\Middleware\EnsureUserHasPermission::class,
            'cart.identity' => \App\Http\Middleware\EnsureCartIdentity::class,
            'optional.auth' => \App\Http\Middleware\OptionalSanctumAuth::class,
            'password.reset' => \App\Http\Middleware\EnsurePasswordReset::class,
            'branch.access' => \App\Http\Middleware\EnsureBranchAccess::class,
            'customer.active' => \App\Http\Middleware\EnsureCustomerActive::class,
            'inventory.enabled' => \App\Http\Middleware\EnsureInventoryEnabled::class,
            'token.staff' => \App\Http\Middleware\EnsureStaffToken::class,
            'staff.active' => \App\Http\Middleware\EnsureStaffActive::class,
        ]);

        // Fail-open request logging for feedback correlation. Appended so it runs
        // after route middleware resolve the authenticated user (see LogRequest).
        $middleware->api(append: [
            \App\Http\Middleware\LogRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Answer unauthenticated API requests with a 401 instead of redirecting.
         *
         * Laravel's default handler sends an unauthenticated request to
         * route('login'). This app is API-only (apiPrefix 'v1') and defines no
         * such route, so every expired or missing token raised
         * RouteNotFoundException: "Route [login] not defined" and returned 500.
         *
         * The cost was not just the wrong status code. A routine expired session
         * — the most ordinary event there is — surfaced in the platform error
         * feed as an unexplained application error, several times an hour, which
         * is how a feed stops being read at all. Clients also could not tell
         * "log in again" from "the server broke", so they had no way to recover.
         *
         * The redirect only applies when the request does not expect JSON, so
         * this mainly bites callers that omit `Accept: application/json`.
         */
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->unauthorized(
                'Your session has expired. Please sign in again.',
                'unauthenticated',
            );
        });
    })->create();
