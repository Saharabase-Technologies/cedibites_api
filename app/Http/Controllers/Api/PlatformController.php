<?php

namespace App\Http\Controllers\Api;

use App\Enums\BranchRule;
use App\Enums\EmployeeStatus;
use App\Enums\Role;
use App\Events\CustomerSessionEvent;
use App\Events\StaffSessionEvent;
use App\Http\Controllers\Controller;
use App\Models\AcknowledgedError;
use App\Models\Employee;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Notifications\StaffAccountCreatedNotification;
use App\Services\SessionDeviceService;
use App\Services\SmartErrorService;
use App\Services\SmsHealthService;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class PlatformController extends Controller
{
    /** A session that has made a request this recently is somebody at a screen. */
    private const SESSION_ONLINE_SECONDS = 300;

    /** Beyond this it is a terminal left signed in, not a person working. */
    private const SESSION_IDLE_SECONDS = 1800;

    public function __construct(
        private SystemHealthService $healthService,
        private SmartErrorService $errorService,
        private SmsHealthService $smsHealthService,
        private SessionDeviceService $devices,
    ) {}

    /**
     * System health overview.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'data' => $this->healthService->check(),
        ]);
    }

    /**
     * SMS delivery health, with the recent failures behind the verdict.
     */
    public function smsHealth(Request $request): JsonResponse
    {
        $window = min(max((int) ($request->window ?? 24), 1), 24 * 30);

        $health = $this->smsHealthService->check($window);

        // Scoped the same way as the verdict above it, or the list would
        // contradict the headline — a quiet campaign failure showing up under a
        // "healthy" status reads as a bug in the page.
        $health['recent_failures'] = SmsDeliveryAttempt::query()
            ->transactional()
            ->where('succeeded', false)
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (SmsDeliveryAttempt $a) => [
                'id' => $a->id,
                'notification' => $a->notification,
                'recipient' => $this->maskPhone((string) $a->recipient),
                'reason' => $a->failure_reason?->value,
                'error' => $a->error_message,
                'failed_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $health]);
    }

    /**
     * Smart error feed — business-friendly error summaries.
     */
    public function errors(Request $request): JsonResponse
    {
        $limit = min((int) ($request->limit ?? 50), 100);

        return response()->json([
            'data' => $this->errorService->getFeed(
                $limit,
                $request->boolean('include_acknowledged'),
            ),
        ]);
    }

    /**
     * Mark one fault as dealt with, so the feed stops showing it.
     *
     * Not passcode-gated. A passcode belongs on the actions that cannot be
     * undone; this one can be undone by the button next to it, and putting a
     * six-digit code in front of dismissing a notice is how a feed ends up
     * permanently full of notices nobody dismisses.
     */
    public function acknowledgeError(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
            'severity' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        AcknowledgedError::updateOrCreate(
            ['fingerprint' => $validated['fingerprint']],
            [
                'title' => $validated['title'],
                'category' => $validated['category'] ?? null,
                'severity' => $validated['severity'] ?? null,
                'note' => $validated['note'] ?? null,
                'acknowledged_by' => $request->user()?->id,
                'acknowledged_at' => now(),
            ],
        );

        activity('platform')
            ->causedBy($request->user())
            ->event('error_acknowledged')
            ->withProperties(['fingerprint' => $validated['fingerprint'], 'title' => $validated['title']])
            ->log("Acknowledged: {$validated['title']}");

        return response()->json(['message' => 'Acknowledged.']);
    }

    /**
     * Put a fault back on the feed.
     */
    public function unacknowledgeError(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        $ack = AcknowledgedError::where('fingerprint', $validated['fingerprint'])->first();

        if (! $ack) {
            return response()->json(['message' => 'That was not acknowledged.'], 404);
        }

        $ack->delete();

        activity('platform')
            ->causedBy($request->user())
            ->event('error_unacknowledged')
            ->withProperties(['fingerprint' => $validated['fingerprint']])
            ->log("Reopened: {$ack->title}");

        return response()->json(['message' => 'Back on the feed.']);
    }

    /**
     * Acknowledge everything currently outstanding, in one go.
     *
     * The client sends the fingerprints it can actually see rather than the
     * server clearing the whole feed, so a fault that arrives between the page
     * rendering and the button being pressed is not silently swallowed along
     * with the ones the reader actually looked at.
     */
    public function acknowledgeAllErrors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'errors' => ['required', 'array', 'min:1', 'max:100'],
            'errors.*.fingerprint' => ['required', 'string', 'size:64'],
            'errors.*.title' => ['required', 'string', 'max:255'],
            'errors.*.category' => ['nullable', 'string', 'max:40'],
            'errors.*.severity' => ['nullable', 'string', 'max:20'],
        ]);

        $now = now();
        $userId = $request->user()?->id;

        foreach ($validated['errors'] as $error) {
            AcknowledgedError::updateOrCreate(
                ['fingerprint' => $error['fingerprint']],
                [
                    'title' => $error['title'],
                    'category' => $error['category'] ?? null,
                    'severity' => $error['severity'] ?? null,
                    'acknowledged_by' => $userId,
                    'acknowledged_at' => $now,
                ],
            );
        }

        $count = count($validated['errors']);

        activity('platform')
            ->causedBy($request->user())
            ->event('errors_acknowledged')
            ->withProperties(['count' => $count])
            ->log("Acknowledged {$count} error(s)");

        return response()->json(['message' => "Acknowledged {$count} item(s)."]);
    }

    /**
     * Failed jobs list with retry/delete capability.
     */
    public function failedJobs(): JsonResponse
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $firstLine = trim(explode("\n", $job->exception)[0]);

                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'job' => class_basename($payload['displayName'] ?? 'Unknown'),
                    'queue' => $job->queue,
                    'connection' => $job->connection,
                    'error' => mb_substr($firstLine, 0, 200),
                    'failed_at' => $job->failed_at,
                ];
            });

        return response()->json([
            'data' => $jobs,
            'meta' => [
                // The list is capped at 50 but the queue is not, and a backlog
                // of hundreds reading as "50" is how one stops being alarming.
                'total' => DB::table('failed_jobs')->count(),
            ],
        ]);
    }

    /**
     * Drop one failed job from the queue without retrying it.
     *
     * Passcode-gated and irreversible: `queue:forget` deletes the row, and with
     * it the payload, so the job can never be retried afterwards. Use it on
     * failures that have been understood, not on ones that have merely been
     * read.
     */
    public function forgetJob(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate(['uuid' => ['required', 'string']]);

        $job = DB::table('failed_jobs')->where('uuid', $validated['uuid'])->first();

        if (! $job) {
            return response()->json(['message' => 'That job is no longer in the queue.'], 404);
        }

        $payload = json_decode($job->payload, true);

        DB::table('failed_jobs')->where('uuid', $validated['uuid'])->delete();

        activity('platform')
            ->causedBy($request->user())
            ->event('job_forgotten')
            ->withProperties([
                'uuid' => $validated['uuid'],
                'job' => class_basename($payload['displayName'] ?? 'Unknown'),
            ])
            ->log("Cleared failed job: {$validated['uuid']}");

        return response()->json(['message' => 'Cleared from the queue.']);
    }

    /**
     * Empty the failed queue.
     */
    public function flushJobs(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'The failed queue is already empty.']);
        }

        Artisan::call('queue:flush');

        activity('platform')
            ->causedBy($request->user())
            ->event('jobs_flushed')
            ->withProperties(['count' => $count])
            ->log("Cleared {$count} failed job(s) from the queue");

        return response()->json(['message' => "Cleared {$count} failed job(s)."]);
    }

    /**
     * Retry a failed job.
     */
    public function retryJob(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $request->validate(['uuid' => ['required', 'string']]);

        Artisan::call('queue:retry', ['id' => [$request->uuid]]);

        activity('platform')
            ->causedBy($request->user())
            ->event('job_retried')
            ->withProperties(['uuid' => $request->uuid])
            ->log("Retried failed job: {$request->uuid}");

        return response()->json(['message' => 'Job queued for retry']);
    }

    /**
     * Reset a staff member's password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'new_password' => ['nullable', 'string', 'min:8'],
            'force_reset' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::with('user')->findOrFail($validated['employee_id']);
        $user = $employee->user;

        // Generate a simple password if none provided
        $password = $validated['new_password'] ?? $this->generateSimplePassword();
        $forceReset = $validated['force_reset'] ?? false;

        $user->update([
            'password' => $password,
            'recoverable_password' => $password,
            'must_reset_password' => $forceReset,
            'password_reset_required_at' => $forceReset ? now() : null,
        ]);

        // Revoke all existing tokens so they must re-login
        $user->tokens()->delete();

        activity('platform')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('password_reset')
            ->withProperties(['employee_id' => $employee->id, 'employee_no' => $employee->employee_no, 'force_reset' => $forceReset])
            ->log("Platform admin reset password for {$user->name} ({$employee->employee_no})");

        return response()->json([
            'message' => 'Password reset successfully',
            'temporary_password' => $password,
            'must_reset' => $forceReset,
        ]);
    }

    /**
     * List all employees with their recoverable passwords (passcode-gated).
     */
    public function staffPasswords(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        activity('platform')
            ->causedBy($request->user())
            ->event('passwords_viewed')
            ->log('Platform admin viewed staff password list');

        $employees = Employee::with(['user.roles', 'branches'])
            ->whereHas('user')
            ->get()
            ->map(fn (Employee $emp) => [
                'id' => $emp->id,
                'user_id' => $emp->user->id,
                'name' => $emp->user->name,
                'phone' => $emp->user->phone,
                'employee_no' => $emp->employee_no,
                'role' => $emp->user->getRoleNames()->first(),
                'branches' => $emp->branches->pluck('name'),
                'status' => $emp->status->value,
                'password' => $emp->user->recoverable_password,
                'has_password' => $emp->user->recoverable_password !== null,
                'must_reset_password' => $emp->user->must_reset_password,
            ]);

        return response()->json(['data' => $employees]);
    }

    /**
     * View a single employee's recoverable password (passcode-gated, logged).
     */
    public function viewPassword(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $employee = Employee::with('user')->findOrFail($validated['employee_id']);

        activity('platform')
            ->causedBy($request->user())
            ->performedOn($employee->user)
            ->event('password_viewed')
            ->withProperties(['employee_id' => $employee->id, 'employee_no' => $employee->employee_no])
            ->log("Platform admin viewed password for {$employee->user->name} ({$employee->employee_no})");

        return response()->json([
            'data' => [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'employee_no' => $employee->employee_no,
                'password' => $employee->user->recoverable_password,
                'has_password' => $employee->user->recoverable_password !== null,
                'must_reset_password' => $employee->user->must_reset_password,
            ],
        ]);
    }

    /**
     * List all platform admins.
     */
    public function listAdmins(): JsonResponse
    {
        $admins = User::role(Role::TechAdmin->value)
            ->with('employee')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
                'employee_no' => $u->employee?->employee_no,
                'has_passcode' => $u->platform_passcode !== null,
                'created_at' => $u->created_at?->toIso8601String(),
                'last_login' => $u->tokens()->latest()->first()?->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $admins]);
    }

    /**
     * Promote an existing employee to platform admin.
     */
    public function createAdmin(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'new_passcode' => ['required', 'string', 'digits:6'],
        ]);

        $employee = Employee::with('user')->findOrFail($validated['employee_id']);
        $user = $employee->user;

        // Prevent self-escalation
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot modify your own platform admin status'], 422);
        }

        // One role per user — promotion replaces, it does not stack. Leaving the
        // previous role attached produced accounts reporting themselves as
        // "Branch Manager" while holding every platform permission, because every
        // screen reads roles->first() and permissions are the union.
        $user->syncRoles([Role::TechAdmin->value]);
        $user->syncPermissions([]);
        $user->update(['platform_passcode' => $validated['new_passcode']]);

        activity('platform')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('admin_created')
            ->withProperties(['employee_id' => $employee->id])
            ->log("Promoted {$user->name} to platform admin");

        return response()->json(['message' => "{$user->name} is now a platform admin"]);
    }

    /**
     * Create a brand-new staff/admin user from the password vault (passcode-gated).
     *
     * Mirrors EmployeeController::store but lives in the tech-admin scope and is
     * verified by the platform passcode. Stores the recoverable password so the
     * created user is immediately viewable/manageable in the vault. When the role
     * is tech_admin, a 6-digit passcode for the new admin is also required.
     */
    public function createUser(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Email or phone — at least one is required (partners may have email only).
            'phone' => ['nullable', 'string', 'max:20', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'role' => ['required', 'string', Rule::in(Role::values())],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'password_mode' => ['nullable', 'in:auto,custom,prompt'],
            'password' => ['nullable', 'string', 'min:8', 'required_if:password_mode,custom'],
            'new_passcode' => ['required_if:role,'.Role::TechAdmin->value, 'nullable', 'string', 'digits:6'],
        ], [
            'phone.required_without' => 'Provide an email or a phone number.',
            'email.required_without' => 'Provide an email or a phone number.',
        ]);

        // Canonicalise the phone so it matches at login regardless of input format.
        $phone = User::normalizePhone($validated['phone'] ?? null);

        // Branch requirement comes from the role, not from a list kept here —
        // see Role::branchRule(). Head office, the warehouse and the call centre
        // are company-wide and take none; a manager takes exactly one.
        $branchRule = Role::from($validated['role'])->branchRule();
        $branchIds = array_values(array_unique($validated['branch_ids'] ?? []));

        if ($branchRule === BranchRule::None) {
            $branchIds = [];
        } elseif ($branchIds === []) {
            return response()->json(['message' => 'At least one branch is required for this role.'], 422);
        } elseif ($branchRule === BranchRule::ExactlyOne && count($branchIds) > 1) {
            return response()->json(['message' => 'This role is assigned exactly one branch.'], 422);
        }

        DB::beginTransaction();

        try {
            $passwordMode = $validated['password_mode'] ?? 'auto';

            // Determine password based on mode (mirrors EmployeeController::store).
            if ($passwordMode === 'prompt') {
                $password = $this->generateSimplePassword();
                $mustReset = true;
                $storeRecoverable = false;
            } elseif ($passwordMode === 'custom' && ! empty($validated['password'])) {
                $password = $validated['password'];
                $mustReset = false;
                $storeRecoverable = true;
            } else {
                $password = $this->generateSimplePassword();
                $mustReset = false;
                $storeRecoverable = true;
            }

            // Reuse an existing user (e.g. previously a customer) matched by phone
            // or email, else create a fresh one.
            $existingUser = null;
            if ($phone) {
                $existingUser = User::where('phone', $phone)->first();
            }
            if (! $existingUser && ! empty($validated['email'])) {
                $existingUser = User::where('email', $validated['email'])->first();
            }

            if ($existingUser) {
                $existingUser->update([
                    'name' => $validated['name'],
                    'password' => Hash::make($password),
                    'recoverable_password' => $storeRecoverable ? $password : null,
                    'must_reset_password' => $mustReset,
                ]);
                if (! empty($validated['email']) && ! $existingUser->email) {
                    $existingUser->update(['email' => $validated['email']]);
                }
                if ($phone && ! $existingUser->phone) {
                    $existingUser->update(['phone' => $phone]);
                }
                $user = $existingUser;
            } else {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $phone,
                    'password' => Hash::make($password),
                    'recoverable_password' => $storeRecoverable ? $password : null,
                    'must_reset_password' => $mustReset,
                ]);
            }

            // One role per user — see createAdmin. Reusing an existing account
            // (a customer being brought on staff, a former employee returning)
            // must not leave the old role attached.
            $user->syncRoles([$validated['role']]);
            $user->syncPermissions([]);

            // Tech admins additionally get their own platform passcode.
            if ($validated['role'] === Role::TechAdmin->value) {
                $user->update(['platform_passcode' => $validated['new_passcode']]);
            }

            // Derive the next employee number inside the transaction (race-safe).
            $maxNo = Employee::lockForUpdate()
                ->where('employee_no', 'like', 'EMP%')
                ->pluck('employee_no')
                ->map(fn (string $no) => (int) substr($no, 3))
                ->max() ?? 0;
            $nextNo = 'EMP'.str_pad((int) $maxNo + 1, 5, '0', STR_PAD_LEFT);

            $employee = Employee::create([
                'user_id' => $user->id,
                'employee_no' => $nextNo,
                'hire_date' => now(),
                'status' => EmployeeStatus::Active->value,
            ]);
            $employee->branches()->sync($branchIds);

            DB::commit();

            // Past the commit — a dead SMS gateway must not report the account
            // as failed when it exists. See EmployeeController::notifyQuietly.
            try {
                $user->notify(new StaffAccountCreatedNotification($password, $validated['role']));
            } catch (\Throwable $e) {
                \Log::warning('Staff notification failed to send', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            activity('platform')
                ->causedBy($request->user())
                ->performedOn($user)
                ->event('user_created')
                ->withProperties(['employee_id' => $employee->id, 'employee_no' => $nextNo, 'role' => $validated['role']])
                ->log("Created {$validated['role']} user {$user->name} ({$nextNo})");

            return response()->json([
                'message' => "{$user->name} created successfully",
                'name' => $user->name,
                'employee_no' => $nextNo,
                'role' => $validated['role'],
                // Surfaced once so the tech admin can share it confidentially.
                'generated_password' => $passwordMode === 'custom' ? null : $password,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to create user: '.$e->getMessage()], 500);
        }
    }

    /**
     * Revoke platform admin from a user.
     */
    public function revokeAdmin(Request $request, User $user): JsonResponse
    {
        $this->verifyPasscode($request);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot revoke your own platform admin access'], 422);
        }

        $user->removeRole(Role::TechAdmin->value);
        $user->update(['platform_passcode' => null]);

        activity('platform')
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('admin_revoked')
            ->log("Revoked platform admin from {$user->name}");

        return response()->json(['message' => "Platform admin revoked from {$user->name}"]);
    }

    /**
     * Update the authenticated user's passcode.
     */
    public function updatePasscode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_passcode' => ['required', 'string', 'digits:6'],
            'new_passcode' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_passcode'], $user->platform_passcode)) {
            return response()->json(['message' => 'Current passcode is incorrect'], 422);
        }

        $user->update(['platform_passcode' => $validated['new_passcode']]);

        activity('platform')
            ->causedBy($user)
            ->event('passcode_changed')
            ->log('Platform admin changed their passcode');

        return response()->json(['message' => 'Passcode updated']);
    }

    /**
     * Clear application caches.
     */
    public function clearCache(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $type = $request->validate(['type' => ['required', 'in:all,config,route,view,app']])['type'];

        match ($type) {
            'all' => collect(['cache:clear', 'config:clear', 'route:clear', 'view:clear'])
                ->each(fn ($cmd) => Artisan::call($cmd)),
            'config' => Artisan::call('config:clear'),
            'route' => Artisan::call('route:clear'),
            'view' => Artisan::call('view:clear'),
            'app' => Artisan::call('cache:clear'),
        };

        activity('platform')
            ->causedBy($request->user())
            ->event('cache_cleared')
            ->withProperties(['type' => $type])
            ->log("Cleared {$type} cache");

        return response()->json(['message' => "Cache ({$type}) cleared successfully"]);
    }

    /**
     * Toggle maintenance mode.
     */
    public function toggleMaintenance(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
            $status = 'live';
        } else {
            Artisan::call('down', ['--secret' => bin2hex(random_bytes(16))]);
            $status = 'maintenance';
        }

        activity('platform')
            ->causedBy($request->user())
            ->event('maintenance_toggled')
            ->withProperties(['status' => $status])
            ->log("System set to {$status} mode");

        return response()->json([
            'message' => "System is now in {$status} mode",
            'status' => $status,
        ]);
    }

    /**
     * Active sessions overview.
     */
    public function activeSessions(Request $request): JsonResponse
    {
        // Sanctum expires a token `expiration` minutes after it was created,
        // and nothing prunes the dead rows — there is no `sanctum:prune-expired`
        // on the schedule. So the cutoff has to be applied here, or the list
        // shows sessions that cannot make a single request as though somebody
        // were signed in on them.
        $expiration = config('sanctum.expiration');
        $mintedAfter = $expiration ? now()->subMinutes((int) $expiration) : null;

        $currentTokenId = $request->user()?->currentAccessToken()?->id;

        $query = DB::table('personal_access_tokens')
            ->join('users', 'personal_access_tokens.tokenable_id', '=', 'users.id')
            ->leftJoin('employees', 'users.id', '=', 'employees.user_id')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->whereNotNull('personal_access_tokens.last_used_at');

        if ($mintedAfter) {
            $query->where('personal_access_tokens.created_at', '>=', $mintedAfter);
        }

        $tokens = $query
            ->select([
                'personal_access_tokens.id as token_id',
                'users.id as user_id',
                'users.name',
                'users.phone',
                'employees.employee_no',
                'personal_access_tokens.name as token_name',
                'personal_access_tokens.user_agent',
                'personal_access_tokens.last_used_at',
                'personal_access_tokens.created_at as token_created_at',
            ])
            ->orderByDesc('personal_access_tokens.last_used_at')
            ->limit(200)
            ->get();

        $posting = $this->postings($tokens->pluck('user_id')->unique()->all());

        $sessions = $tokens->map(function ($t) use ($currentTokenId, $expiration, $posting) {
            $lastUsed = Carbon::parse($t->last_used_at);
            $created = Carbon::parse($t->token_created_at);
            $where = $posting[$t->user_id] ?? ['role' => null, 'branches' => null];

            return [
                'token_id' => (int) $t->token_id,
                'user_id' => $t->user_id,
                'name' => $t->name,
                'phone' => $t->phone,
                'employee_no' => $t->employee_no,
                'role' => $where['role'],
                // Null for anyone the branch question does not apply to — an
                // admin or a tech admin belongs to the company, not a branch,
                // and printing "no branch" against their name reads as a gap in
                // the data rather than the answer.
                'branches' => $where['branches'],
                'token_type' => $t->token_name === 'employee-auth-token' ? 'staff' : 'customer',
                'device' => $this->devices->classify($t->user_agent),
                'browser' => $this->devices->browser($t->user_agent),
                'status' => $this->sessionStatus($lastUsed),
                'idle_seconds' => (int) $lastUsed->diffInSeconds(now()),
                'last_active' => $lastUsed->toIso8601String(),
                'session_started' => $created->toIso8601String(),
                'expires_at' => $expiration
                    ? $created->copy()->addMinutes((int) $expiration)->toIso8601String()
                    : null,
                'is_current' => (int) $t->token_id === (int) $currentTokenId,
            ];
        });

        return response()->json([
            'data' => $sessions,
            'meta' => [
                // "Right now" is the only figure worth reading at a glance. The
                // rest of the list is people who used the app today and walked
                // away without signing out, which is every POS terminal.
                'online' => $sessions->where('status', 'online')->count(),
                'idle' => $sessions->where('status', 'idle')->count(),
                'away' => $sessions->where('status', 'away')->count(),
                'online_window_seconds' => self::SESSION_ONLINE_SECONDS,
                'idle_window_seconds' => self::SESSION_IDLE_SECONDS,
            ],
        ]);
    }

    /**
     * Role and branch for each signed-in person, in one pass.
     *
     * Branches come back null rather than empty for company-wide roles.
     * `isCompanyWide()` reads the role's branch rule, and an admin genuinely
     * having no branch row is the correct state for them — the distinction
     * matters because an empty list on a cashier means something has gone
     * wrong, and on an admin it means nothing at all.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{role: string|null, branches: array<int, string>|null}>
     */
    private function postings(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->with(['roles:id,name', 'employee.branches:id,name'])
            ->get()
            ->mapWithKeys(fn (User $user) => [
                $user->id => [
                    'role' => $user->getRoleNames()->first(),
                    'branches' => $user->isCompanyWide()
                        ? null
                        : $user->employee?->branches->pluck('name')->values()->all(),
                ],
            ])
            ->all();
    }

    /**
     * Sign one device out.
     */
    public function revokeSession(Request $request, int $token): JsonResponse
    {
        $this->verifyPasscode($request);

        $row = DB::table('personal_access_tokens')
            ->where('id', $token)
            ->where('tokenable_type', User::class)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'That session has already ended.'], 404);
        }

        if ($token === (int) $request->user()?->currentAccessToken()?->id) {
            return response()->json([
                'message' => 'That is the session you are using right now. Use Sign out to end it.',
            ], 422);
        }

        $user = User::find($row->tokenable_id);

        DB::table('personal_access_tokens')->where('id', $token)->delete();

        $this->announceIfFullySignedOut($user);

        activity('platform')
            ->causedBy($request->user())
            ->event('session_revoked')
            ->withProperties([
                'token_id' => $token,
                'token_name' => $row->name,
                'target_user_id' => $row->tokenable_id,
            ])
            ->log('Signed out one device for '.($user?->name ?? "user {$row->tokenable_id}"));

        return response()->json(['message' => 'Signed that device out.']);
    }

    /**
     * Sign out a set of devices in one go.
     *
     * The client sends the token ids it can actually see rather than a category
     * name, so a till that came online between the page rendering and the
     * button being pressed is not swept up with the ones the reader looked at.
     * Their own session is skipped rather than refused — one live session in a
     * selection of forty is not a reason to make somebody redo the selection.
     */
    public function revokeSessions(Request $request): JsonResponse
    {
        $this->verifyPasscode($request);

        $validated = $request->validate([
            'token_ids' => ['required', 'array', 'min:1', 'max:200'],
            'token_ids.*' => ['required', 'integer'],
        ]);

        $currentTokenId = (int) $request->user()?->currentAccessToken()?->id;
        $requested = collect($validated['token_ids'])->map(fn ($id) => (int) $id)->unique();
        $skippedSelf = $requested->contains($currentTokenId);

        $targets = DB::table('personal_access_tokens')
            ->whereIn('id', $requested->reject(fn ($id) => $id === $currentTokenId)->all())
            ->where('tokenable_type', User::class)
            ->get(['id', 'tokenable_id']);

        if ($targets->isEmpty()) {
            return response()->json([
                'message' => $skippedSelf
                    ? 'The only session selected was your own.'
                    : 'Those sessions have already ended.',
            ], 404);
        }

        DB::table('personal_access_tokens')->whereIn('id', $targets->pluck('id'))->delete();

        $users = User::whereIn('id', $targets->pluck('tokenable_id')->unique())->get();

        foreach ($users as $user) {
            $this->announceIfFullySignedOut($user);
        }

        $count = $targets->count();

        activity('platform')
            ->causedBy($request->user())
            ->event('sessions_revoked')
            ->withProperties([
                'sessions' => $count,
                'people' => $users->count(),
                'target_user_ids' => $users->pluck('id')->all(),
            ])
            ->log("Signed out {$count} device(s) across {$users->count()} person/people");

        $message = "Signed out {$count} device".($count === 1 ? '' : 's').'.';

        return response()->json([
            'message' => $skippedSelf ? $message.' Your own session was left alone.' : $message,
        ]);
    }

    /**
     * Sign one person out of every device they are signed in on.
     */
    public function revokeUserSessions(Request $request, User $user): JsonResponse
    {
        $this->verifyPasscode($request);

        if ($user->id === $request->user()?->id) {
            return response()->json([
                'message' => 'You cannot sign yourself out from here. Use Sign out.',
            ], 422);
        }

        $tokenNames = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->pluck('name');

        if ($tokenNames->isEmpty()) {
            return response()->json(['message' => 'They have no active sessions.'], 404);
        }

        $user->tokens()->delete();

        $this->announce($user, $tokenNames);

        activity('platform')
            ->causedBy($request->user())
            ->event('all_sessions_revoked')
            ->withProperties([
                'sessions' => $tokenNames->count(),
                'target_user_id' => $user->id,
            ])
            ->log("Signed {$user->name} out of {$tokenNames->count()} device(s)");

        return response()->json([
            'message' => "Signed {$user->name} out of {$tokenNames->count()} device(s).",
        ]);
    }

    /**
     * Tell a person's screens to clear, but only once nothing is left to clear.
     *
     * `StaffSessionEvent` and `CustomerSessionEvent` publish to
     * `App.Models.User.{id}`, which every device that person holds is
     * subscribed to. There is no way to address one device, so the broadcast is
     * only safe when no device is meant to survive — otherwise ending the till
     * would sign them out of the phone in their pocket too. When a session does
     * survive, the revoked device finds out on its next request: 401, and the
     * client's own interceptor drops the token and sends it to the login
     * screen. On a POS or kitchen display, which poll constantly, that is a
     * matter of seconds.
     */
    private function announceIfFullySignedOut(?User $user): void
    {
        if (! $user || $user->tokens()->exists()) {
            return;
        }

        $this->announce($user, collect(['employee-auth-token', 'auth-token']));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $tokenNames
     */
    private function announce(User $user, $tokenNames): void
    {
        // One person can hold both kinds at once — the model is one user with
        // many roles, so a cashier who also orders from the app has a staff
        // token and a customer token. Each client listens on its own event
        // name, so both have to go out or one of the two screens hangs on to a
        // session that no longer exists.
        if ($tokenNames->contains('employee-auth-token')) {
            StaffSessionEvent::dispatch($user, 'session.revoked');
        }

        if ($tokenNames->contains(fn ($name) => $name !== 'employee-auth-token')) {
            CustomerSessionEvent::dispatch($user);
        }
    }

    /**
     * How live a session is, from the last request it made.
     */
    private function sessionStatus(Carbon $lastUsed): string
    {
        $idle = $lastUsed->diffInSeconds(now());

        return match (true) {
            $idle <= self::SESSION_ONLINE_SECONDS => 'online',
            $idle <= self::SESSION_IDLE_SECONDS => 'idle',
            default => 'away',
        };
    }

    /**
     * Mask a phone for display, e.g. "+233241234567" → "+2332•••••67".
     * Enough to recognise a number you already know, not enough to harvest one.
     */
    private function maskPhone(string $phone): string
    {
        if (mb_strlen($phone) < 6) {
            return $phone;
        }

        return mb_substr($phone, 0, 5).str_repeat('•', 5).mb_substr($phone, -2);
    }

    /**
     * Verify the 6-digit passcode for sensitive operations.
     */
    private function verifyPasscode(Request $request): void
    {
        $request->validate(['passcode' => ['required', 'string', 'digits:6']]);

        $user = $request->user();

        if (! $user->platform_passcode) {
            abort(422, 'No passcode set. Contact another platform admin.');
        }

        if (! Hash::check($request->passcode, $user->platform_passcode)) {
            activity('platform')
                ->causedBy($user)
                ->event('passcode_failed')
                ->log('Failed passcode verification');

            abort(403, 'Invalid passcode');
        }
    }

    private function generateSimplePassword(): string
    {
        $adjectives = ['Happy', 'Bright', 'Quick', 'Calm', 'Bold', 'Warm', 'Fair', 'Kind', 'Wise', 'Keen'];
        $nouns = ['Star', 'Moon', 'Wave', 'Sun', 'Tree', 'Bird', 'Lake', 'Rock', 'Wind', 'Fire'];
        $specials = ['!', '@', '#', '$', '%'];

        return $adjectives[array_rand($adjectives)]
             .$nouns[array_rand($nouns)]
             .random_int(10, 999)
             .$specials[array_rand($specials)];
    }
}
