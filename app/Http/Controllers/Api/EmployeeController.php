<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Events\StaffSessionEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeNote;
use App\Models\User;
use App\Notifications\PasswordResetRequiredNotification;
use App\Notifications\StaffAccountCreatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with(['user.roles.permissions', 'user.permissions', 'branches']);

        // Branch partners may only list employees from their assigned branches,
        // regardless of any branch_id passed in the request.
        $user = $request->user();
        if ($user && $user->hasRole('branch_partner')) {
            $assigned = $user->employee
                ? $user->employee->branches()->pluck('branches.id')->all()
                : [];
            $query->whereHas('branches', function ($q) use ($assigned) {
                $q->whereIn('branches.id', $assigned ?: [-1]);
            });
        }

        if ($request->has('branch_id')) {
            $query->whereHas('branches', function ($q) use ($request) {
                $q->where('branches.id', $request->branch_id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $employees = $query->latest()->paginate($request->per_page ?? 15);

        return response()->success(
            EmployeeResource::collection($employees)->response()->getData(true),
            'Employees retrieved successfully.'
        );
    }

    /**
     * List employees with active auth tokens (active sessions).
     */
    public function activeSessions(): JsonResponse
    {
        $employees = Employee::with(['user', 'branches'])
            ->whereHas('user', fn ($q) => $q->whereHas('tokens'))
            ->get()
            ->map(fn (Employee $emp) => [
                'id' => $emp->id,
                'name' => $emp->user->name,
                'phone' => $emp->user->phone,
                'status' => $emp->status->value,
                'branches' => $emp->branches->pluck('name'),
                'active_tokens' => $emp->user->tokens()->count(),
                'last_used_at' => $emp->user->tokens()->latest('last_used_at')->value('last_used_at'),
            ]);

        return response()->success($employees);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateEmployeeRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $passwordMode = $request->input('password_mode', 'auto');

            // Determine password based on mode
            if ($passwordMode === 'prompt') {
                // User will be prompted to create their own password on first login
                $password = $this->generateSimplePassword();
                $mustReset = true;
                $storeRecoverable = false;
            } elseif ($passwordMode === 'custom' && $request->filled('password')) {
                // Admin is setting the password directly — no forced reset
                $password = $request->password;
                $mustReset = false;
                $storeRecoverable = true;
            } else {
                // Default: auto-generate — admin is providing the password, no forced reset
                $password = $this->generateSimplePassword();
                $mustReset = false;
                $storeRecoverable = true;
            }

            // Check if user with this phone already exists (e.g. registered as customer)
            $existingUser = User::where('phone', $request->phone)->first();

            if ($existingUser) {
                // Reuse the existing user — update credentials for staff access
                $existingUser->update([
                    'name' => $request->name,
                    'password' => Hash::make($password),
                    'recoverable_password' => $storeRecoverable ? $password : null,
                    'must_reset_password' => $mustReset,
                ]);
                if ($request->filled('email') && ! $existingUser->email) {
                    $existingUser->update(['email' => $request->email]);
                }
                $user = $existingUser;
            } else {
                // Create new user
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'password' => Hash::make($password),
                    'recoverable_password' => $storeRecoverable ? $password : null,
                    'must_reset_password' => $mustReset,
                ]);
            }

            // Assign role
            $user->assignRole($request->role);

            // Assign individual permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                $user->givePermissionTo($request->permissions);
            }

            // Create employee with all fields — derive next number from the
            // highest existing suffix inside the transaction to avoid races.
            $maxNo = Employee::lockForUpdate()
                ->where('employee_no', 'like', 'EMP%')
                ->pluck('employee_no')
                ->map(fn (string $no) => (int) substr($no, 3))
                ->max() ?? 0;
            $nextNo = 'EMP'.str_pad((int) $maxNo + 1, 5, '0', STR_PAD_LEFT);

            $employeeData = [
                'user_id' => $user->id,
                'employee_no' => $nextNo,
                'hire_date' => $request->hire_date ?? now(),
                'status' => $request->status ?? EmployeeStatus::Active->value,
            ];

            // Add optional fields if provided
            $optionalFields = [
                'ssnit_number', 'ghana_card_id', 'tin_number',
                'date_of_birth', 'nationality', 'emergency_contact_name',
                'emergency_contact_phone', 'emergency_contact_relationship',
            ];

            foreach ($optionalFields as $field) {
                if ($request->filled($field)) {
                    $employeeData[$field] = $request->$field;
                }
            }

            $employee = Employee::create($employeeData);
            $employee->branches()->sync($request->branch_ids);

            DB::commit();

            $user->notify(new StaffAccountCreatedNotification($password, $request->role));

            return response()->created([
                'employee' => (new EmployeeResource($employee->load(['user.roles.permissions', 'user.permissions', 'branches'])))->resolve(),
                // Surface auto-generated passwords once so the admin can share them
                // confidentially. Null when the admin set the password themselves.
                'generated_password' => $passwordMode === 'custom' ? null : $password,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->error('Failed to create employee: '.$e->getMessage(), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Employee $employee): JsonResponse
    {
        return response()->success(
            new EmployeeResource($employee->load(['user.roles.permissions', 'user.permissions', 'branches'])),
            'Employee retrieved successfully.'
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Update user
            $userData = [];
            if ($request->has('name')) {
                $userData['name'] = $request->name;
            }
            if ($request->has('email')) {
                $userData['email'] = $request->email;
            }
            if ($request->has('phone')) {
                $userData['phone'] = $request->phone;
            }

            if (! empty($userData)) {
                $employee->user->update($userData);
            }

            // Update role if provided
            if ($request->has('role')) {
                $oldRole = $employee->user->getRoleNames()->first();
                $employee->user->syncRoles([$request->role]);
                if ($oldRole !== $request->role) {
                    activity('admin')
                        ->causedBy($request->user())
                        ->performedOn($employee)
                        ->event('role_changed')
                        ->withProperties([
                            'employee_name' => $employee->user->name,
                            'old_role' => $oldRole,
                            'new_role' => $request->role,
                        ])
                        ->log("Role changed: {$employee->user->name} from {$oldRole} to {$request->role}");
                }
            }

            // Update individual permissions if provided
            if ($request->has('permissions') && is_array($request->permissions)) {
                $employee->user->syncPermissions($request->permissions);
            }

            // Update employee basic fields
            $employeeData = [];
            if ($request->has('status')) {
                $employeeData['status'] = $request->status;
            }
            if ($request->has('hire_date')) {
                $employeeData['hire_date'] = $request->hire_date;
            }

            // Update HR fields if provided
            $hrFields = [
                'ssnit_number', 'ghana_card_id', 'tin_number',
                'date_of_birth', 'nationality', 'emergency_contact_name',
                'emergency_contact_phone', 'emergency_contact_relationship',
            ];

            foreach ($hrFields as $field) {
                if ($request->has($field)) {
                    $employeeData[$field] = $request->$field;
                }
            }

            if (! empty($employeeData)) {
                $employee->update($employeeData);
            }

            // Update branch assignments
            if ($request->has('branch_ids')) {
                $employee->branches()->sync($request->branch_ids);
            }

            DB::commit();

            $freshUser = $employee->fresh(['user.employee.branches', 'user.roles', 'user.permissions'])->user;
            StaffSessionEvent::dispatch($freshUser, 'user.updated');

            return response()->success(
                new EmployeeResource($employee->fresh(['user.roles.permissions', 'user.permissions', 'branches'])),
                'Employee updated successfully.'
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->error('Failed to update employee: '.$e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Employee $employee): JsonResponse
    {
        $employee->update(['status' => EmployeeStatus::Suspended->value]);

        // Revoke all tokens so suspended employee cannot continue using the API
        $employee->user->tokens()->delete();

        // End any active shifts
        $employee->shifts()->whereNull('logout_at')->update(['logout_at' => now()]);

        StaffSessionEvent::dispatch($employee->user, 'session.revoked');

        return response()->success(null, 'Employee deactivated successfully.');
    }

    /**
     * Force logout an employee by revoking all their tokens.
     */
    public function forceLogout(Employee $employee): JsonResponse
    {
        StaffSessionEvent::dispatch($employee->user, 'session.revoked');

        $employee->user->tokens()->delete();

        // End any active shifts to keep analytics consistent
        $employee->shifts()->whereNull('logout_at')->update(['logout_at' => now()]);

        activity('admin')
            ->causedBy(request()->user())
            ->performedOn($employee)
            ->event('force_logout')
            ->withProperties([
                'employee_name' => $employee->user->name,
                'employee_id' => $employee->id,
            ])
            ->log("Forced logout: {$employee->user->name}");

        return response()->success(null, 'Employee logged out successfully.');
    }

    /**
     * Require password reset for an employee.
     */
    public function requirePasswordReset(Employee $employee): JsonResponse
    {
        $employee->user->update([
            'must_reset_password' => true,
            'password_reset_required_at' => now(),
        ]);

        $employee->user->notify(new PasswordResetRequiredNotification);

        activity('admin')
            ->causedBy(request()->user())
            ->performedOn($employee)
            ->event('password_reset_required')
            ->withProperties([
                'employee_name' => $employee->user->name,
                'employee_id' => $employee->id,
            ])
            ->log("Password reset required: {$employee->user->name}");

        return response()->success(null, 'Password reset required successfully.');
    }

    /**
     * List notes for an employee.
     */
    public function notes(Employee $employee): JsonResponse
    {
        $notes = $employee->notes()
            ->with('author:id,name')
            ->latest()
            ->get()
            ->map(fn (EmployeeNote $note) => [
                'id' => $note->id,
                'content' => $note->content,
                'author' => $note->author->name,
                'created_at' => $note->created_at->toISOString(),
                'is_own' => $note->author_id === request()->user()->id,
            ]);

        return response()->success($notes);
    }

    /**
     * Add a note to an employee.
     */
    public function addNote(Request $request, Employee $employee): JsonResponse
    {
        $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $note = $employee->notes()->create([
            'author_id' => $request->user()->id,
            'content' => $request->content,
        ]);

        $note->load('author:id,name');

        return response()->success([
            'id' => $note->id,
            'content' => $note->content,
            'author' => $note->author->name,
            'created_at' => $note->created_at->toISOString(),
            'is_own' => true,
        ], 'Note added.', 201);
    }

    /**
     * Delete a note (only the author can delete their own notes).
     */
    public function deleteNote(Employee $employee, EmployeeNote $note): JsonResponse
    {
        if ($note->employee_id !== $employee->id) {
            return response()->error('Note does not belong to this employee.', 404);
        }

        if ($note->author_id !== request()->user()->id) {
            return response()->forbidden('You can only delete your own notes.');
        }

        $note->delete();

        return response()->success(null, 'Note deleted.');
    }

    /**
     * Generate a simple, human-friendly temporary password.
     *
     * Format: AdjectiveNoun + 1-3 digits + special char (e.g. HappyBlue42!)
     */
    private function generateSimplePassword(): string
    {
        $adjectives = ['Happy', 'Bright', 'Quick', 'Lucky', 'Cool', 'Bold', 'Sweet', 'Grand', 'Smart', 'Calm', 'Warm', 'Fresh', 'Kind', 'Safe', 'Gold'];
        $nouns = ['Star', 'Blue', 'Wave', 'Moon', 'Tree', 'Lake', 'Fire', 'Rock', 'Bird', 'Lion', 'Bear', 'Rain', 'Peak', 'Sand', 'Box'];
        $specials = ['!', '@', '#', '$'];

        $adjective = $adjectives[array_rand($adjectives)];
        $noun = $nouns[array_rand($nouns)];
        $digits = random_int(10, 999);
        $special = $specials[array_rand($specials)];

        return $adjective.$noun.$digits.$special;
    }
}
