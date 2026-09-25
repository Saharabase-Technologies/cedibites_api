<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    private const ENTITY_SUBJECT_TYPES = [
        'order' => [Order::class, Payment::class],
        'staff' => [User::class, Employee::class],
        'branch' => [Branch::class],
        'menu' => [MenuItem::class, MenuCategory::class, \App\Models\Promo::class, \App\Models\MenuTag::class, \App\Models\MenuAddOn::class],
        'customer' => [Customer::class],
        'system' => [\App\Models\Shift::class],
    ];

    /**
     * Display a paginated listing of activity logs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->with('causer');

        if ($request->filled('entity') && isset(self::ENTITY_SUBJECT_TYPES[$request->entity])) {
            $query->whereIn('subject_type', self::ENTITY_SUBJECT_TYPES[$request->entity]);
        } elseif ($request->filled('entity') && $request->entity === 'auth') {
            $query->where('log_name', 'auth');
        }

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->log_name);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = '%'.$request->search.'%';
            $query->where('description', 'like', $search);
        }

        if ($request->filled('severity')) {
            $warningEvents = ['refunded', 'deleted', 'customer_deleted', 'branch_opened_without_checklist', 'branch_not_open', 'branch_still_not_open', 'opening_grace_expired'];
            $destructiveEvents = ['role_changed', 'customer_suspended'];
            match ($request->severity) {
                'warning' => $query->whereIn('event', $warningEvents),
                'destructive' => $query->whereIn('event', $destructiveEvents),
                'info' => $query->whereNotIn('event', array_merge($warningEvents, $destructiveEvents)),
                default => null,
            };
        }

        $activities = $query->latest()->paginate($request->per_page ?? 20);

        return ActivityLogResource::collection($activities)->response();
    }

    /**
     * Distinct causers (users) that appear in the activity log.
     * Used to power the "filter by user" dropdown on the admin audit page.
     * Optionally narrowed by date range so the list stays relevant.
     */
    public function causers(Request $request): JsonResponse
    {
        $query = ActivityLog::query()
            ->whereNotNull('causer_id')
            ->where('causer_type', User::class);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $causerIds = $query->distinct()->pluck('causer_id');

        $users = User::whereIn('id', $causerIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json([
            'data' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
        ]);
    }
}
