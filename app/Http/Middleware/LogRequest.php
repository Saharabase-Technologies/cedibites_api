<?php

namespace App\Http\Middleware;

use App\Models\RequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one diagnostic row per API request, keyed by the inbound X-Request-ID
 * so a feedback report can pull exactly the backend lines for one user's
 * actions. On an unhandled exception or 5xx, it captures the traceback.
 *
 * I3 (FAIL-OPEN): a failure to write a log row must NEVER turn a real request
 * into a 500 — the write is wrapped, a failure logs a warning and is swallowed.
 *
 * Appended to the `api` group so it runs OUTSIDE route middleware: by the time
 * we read the user (after $next), Sanctum has attributed the authenticated user
 * onto the request (C4).
 */
class LogRequest
{
    private const MESSAGE_CAP = 20_000; // "one traceback, not a novel"

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Log the traceback, then RE-THROW so normal error rendering runs
            // (Laravel's handler still turns this into a response). Exactly one
            // row per request — the success path below won't run for this one.
            $this->write($request, $requestId, $start, null, 'error', $this->formatException($e));
            throw $e;
        }

        $status = $response->getStatusCode();
        $this->write(
            $request,
            $requestId,
            $start,
            $status,
            $status >= 500 ? 'error' : 'info',
            null,
        );

        return $response;
    }

    private function write(
        Request $request,
        string $requestId,
        float $start,
        ?int $status,
        string $level,
        ?string $message,
    ): void {
        try {
            RequestLog::create([
                'request_id' => $requestId,
                'user_id' => $request->user()?->id, // attributed after $next (C4)
                'method' => $request->getMethod(),
                'path' => Str::limit($request->path(), 190, ''),
                'status_code' => $status,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'level' => $level,
                'message' => $message,
            ]);
        } catch (Throwable $e) {
            // I3 — logging is down, the app is not.
            Log::warning('RequestLog write failed', ['error' => $e->getMessage()]);
        }
    }

    private function formatException(Throwable $e): string
    {
        $trace = collect(explode("\n", $e->getTraceAsString()))
            ->take(20)
            ->implode("\n");

        $message = sprintf(
            "%s: %s\n  at %s:%d\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $trace,
        );

        return Str::limit($message, self::MESSAGE_CAP, '…[truncated]');
    }
}
