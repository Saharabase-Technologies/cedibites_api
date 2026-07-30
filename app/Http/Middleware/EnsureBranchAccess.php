<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    /**
     * Verify the authenticated user has access to the {branch} in the route.
     * Managers must manage the branch. Partners must be assigned to it.
     * Super admins and admins bypass this check.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Company-wide roles bypass branch ownership: head office, the
        // warehouse, purchasing and the call centre hold no branch assignment,
        // so "do you own this branch?" has no answer for them and refusing
        // would confine them to nothing at all. See User::isCompanyWide.
        if ($user->isCompanyWide()) {
            return $next($request);
        }

        $branch = $request->route('branch');

        // Fail closed. This middleware only knows how to check a route that binds
        // a {branch}; on any other route it has nothing to compare and cannot
        // honestly say the caller is allowed. Waving those through made the
        // middleware look like it was guarding routes it was doing nothing for —
        // put it on /employees/{employee} or /orders/{order} and it passed
        // everything. Refusing loudly means a misapplied guard shows up as a 500
        // in testing rather than as an open door in production.
        if (! $branch instanceof Branch) {
            \Log::error('branch.access applied to a route with no {branch} binding', [
                'route' => $request->route()?->uri(),
                'user_id' => $user->id,
            ]);

            return response()->json(['message' => 'You do not have access to this branch.'], 403);
        }

        $employee = $user->employee;

        if (! $employee) {
            return response()->json(['message' => 'You do not have access to this branch.'], 403);
        }

        $hasAccess = $employee->branches()->where('branches.id', $branch->id)->exists();

        if (! $hasAccess) {
            \Log::warning('Branch access denied', [
                'user_id' => $user->id,
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
            ]);

            return response()->json(['message' => 'You do not have access to this branch.'], 403);
        }

        return $next($request);
    }
}
