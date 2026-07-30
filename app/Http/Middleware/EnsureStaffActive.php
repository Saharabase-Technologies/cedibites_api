<?php

namespace App\Http\Middleware;

use App\Enums\EmployeeStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse the staff surface to anyone whose employment is not active.
 *
 * EmployeeAuthController::login has always checked this, which made it look
 * handled — but a check at the door only governs people still outside it.
 * Suspending someone mid-shift left the token in their hand working until it
 * expired, and tokens last 24 hours. Revoking on the status change closes that
 * for the tokens we know about; this closes it for the request in flight, and
 * for anything that mints or restores a session by another route.
 *
 * `on_leave` is refused alongside `suspended` and `terminated` on purpose: the
 * login gate already required Active, so this is the same rule applied
 * consistently rather than a new policy. Someone on approved leave who needs to
 * work is marked active again.
 *
 * A request with no employee record passes through — this middleware guards
 * staff status, and whether a non-employee belongs on the route at all is
 * EnsureStaffToken's question and the permission gate's.
 */
class EnsureStaffActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user()?->employee;

        if ($employee && $employee->status !== EmployeeStatus::Active) {
            return response()->json([
                'message' => 'Your staff account is '.$employee->status->value.'. Please contact your administrator.',
                'error' => 'staff_account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
