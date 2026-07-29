<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
        ]);

        // Fail-open request logging for feedback correlation. Appended so it runs
        // after route middleware resolve the authenticated user (see LogRequest).
        $middleware->api(append: [
            \App\Http\Middleware\LogRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
