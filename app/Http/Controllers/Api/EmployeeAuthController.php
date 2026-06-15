<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmployeeStatus;
use App\Events\StaffSessionEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeLoginRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\EmployeeAuthResource;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\StaffPasswordResetNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeAuthController extends Controller
{
    /**
     * Employee login with identifier (email or phone) and password.
     */
    public function login(EmployeeLoginRequest $request): JsonResponse|Response
    {
        $identifier = trim($request->identifier);
        $password = $request->password;

        $field = str_contains($identifier, '@') ? 'email' : 'phone';
        $value = $identifier;

        if ($field === 'phone') {
            $digits = preg_replace('/\D/', '', $identifier);
            $normalized = str_starts_with($digits, '233') ? $digits : '233'.ltrim($digits, '0');
            $value = '+'.ltrim($normalized, '+');
        }

        if (! Auth::attempt([$field => $value, 'password' => $password])) {
            if ($field === 'phone' && $value !== $identifier) {
                if (! Auth::attempt(['phone' => $identifier, 'password' => $password])) {
                    activity('auth')
                        ->withProperties(['identifier' => $identifier, 'ip' => $request->ip()])
                        ->event('staff_login_failed')
                        ->log("Failed staff login attempt for: {$identifier}");

                    return response()->unauthorized('The credentials you entered are incorrect. Please try again.', 'invalid_credentials');
                }
            } else {
                activity('auth')
                    ->withProperties(['identifier' => $identifier, 'ip' => $request->ip()])
                    ->event('staff_login_failed')
                    ->log("Failed staff login attempt for: {$identifier}");

                return response()->unauthorized('The credentials you entered are incorrect. Please try again.', 'invalid_credentials');
            }
        }

        $user = Auth::user();

        if (! $user->employee) {
            Auth::logout();

            return response()->forbidden('User is not an employee');
        }

        if ($user->employee->status !== EmployeeStatus::Active) {
            Auth::logout();

            return response()->forbidden('Your account is currently '.$user->employee->status->value.'. Please contact your administrator.');
        }

        $token = $user->createToken('employee-auth-token')->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->event('staff_login')
            ->log("Staff login: {$user->name} (".$user->employee->branches->pluck('name')->join(', ').')');

        return response()->success([
            'token' => $token,
            'user' => new EmployeeAuthResource($user->load(['employee.branches', 'roles', 'permissions'])),
        ]);
    }

    /**
     * Check whether an active staff account exists for the given identifier.
     *
     * Used by the staff login screen's first step to confirm the account
     * before prompting for a password, and to surface which channels
     * (email / SMS) a password reset can be delivered through.
     *
     * Note: this intentionally reveals account existence to authenticated-looking
     * clients to power a guided two-step login. It is rate limited (10/min) to
     * mitigate enumeration.
     */
    public function checkIdentifier(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim($request->string('identifier'));
        $user = $this->findUserByIdentifier($identifier);

        $exists = $user
            && $user->employee
            && $user->employee->status === EmployeeStatus::Active;

        if (! $exists) {
            return response()->success([
                'exists' => false,
                'channels' => ['email' => false, 'phone' => false],
            ]);
        }

        return response()->success([
            'exists' => true,
            'name' => $user->name,
            'channels' => [
                'email' => (bool) $user->email,
                'phone' => (bool) $user->phone,
            ],
            'email_hint' => $user->email ? $this->maskEmail($user->email) : null,
            'phone_hint' => $user->phone ? $this->maskPhone($user->phone) : null,
        ]);
    }

    /**
     * Return the currently authenticated employee's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['employee.branches', 'roles', 'permissions']);

        return response()->success([
            'user' => new EmployeeAuthResource($user),
        ]);
    }

    /**
     * Change password and clear the must_reset_password flag.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'recoverable_password' => $request->password,
            'must_reset_password' => false,
            'password_reset_required_at' => null,
        ]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Employee logout.
     */
    public function logout(Request $request): Response
    {
        $user = $request->user();

        activity('auth')
            ->causedBy($user)
            ->event('staff_logout')
            ->log('Staff logout');

        StaffSessionEvent::dispatch($user, 'session.revoked');

        $user->tokens()->delete();

        return response()->deleted();
    }

    /**
     * Send a password reset link to the staff member's phone (and email if present).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $identifier = trim($request->string('identifier'));

        $user = $this->findUserByIdentifier($identifier);

        // Always return success to prevent user enumeration
        if (! $user || ! $user->employee) {
            return response()->success(['message' => 'If an account exists, you will receive a reset link shortly.']);
        }

        $token = Str::random(64);
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->upsert(
            [
                'email' => $identifier,
                'token' => Hash::make($token),
                'otp' => Hash::make($otp),
                'otp_expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ],
            uniqueBy: ['email'],
            update: ['token', 'otp', 'otp_expires_at', 'created_at'],
        );

        $resetLink = config('app.frontend_url').'/staff/reset-password?token='.urlencode($token).'&identifier='.urlencode($identifier);

        $user->notify(new StaffPasswordResetNotification($resetLink, $otp));

        return response()->success(['message' => 'If an account exists, you will receive a reset code and link shortly.']);
    }

    /**
     * Reset the staff member's password using a valid token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $identifier = trim($request->string('identifier'));
        $otp = trim($request->string('otp'));
        $usingOtp = $otp !== '';

        $record = DB::table('password_reset_tokens')->where('email', $identifier)->first();

        $invalidMessage = $usingOtp
            ? 'This code is invalid or has already been used.'
            : 'This reset link is invalid or has already been used.';

        if (! $record) {
            return response()->json(['message' => $invalidMessage], 422);
        }

        if ($usingOtp) {
            if (! $record->otp || ! Hash::check($otp, $record->otp)) {
                return response()->json(['message' => $invalidMessage], 422);
            }

            if (! $record->otp_expires_at || Carbon::parse($record->otp_expires_at)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $identifier)->delete();

                return response()->json(['message' => 'This code has expired. Please request a new one.'], 422);
            }
        } else {
            if (! Hash::check($request->string('token'), $record->token)) {
                return response()->json(['message' => $invalidMessage], 422);
            }

            if (Carbon::parse($record->created_at)->addHour()->isPast()) {
                DB::table('password_reset_tokens')->where('email', $identifier)->delete();

                return response()->json(['message' => 'This reset link has expired. Please request a new one.'], 422);
            }
        }

        $user = $this->findUserByIdentifier($identifier);

        if (! $user || ! $user->employee) {
            return response()->json(['message' => 'This reset link is invalid or has already been used.'], 422);
        }

        $user->update([
            'password' => Hash::make($request->string('password')),
            'recoverable_password' => $request->string('password')->toString(),
            'must_reset_password' => false,
            'password_reset_required_at' => null,
        ]);

        DB::table('password_reset_tokens')->where('email', $identifier)->delete();

        return response()->success(['message' => 'Password reset successfully. You can now log in.']);
    }

    /**
     * Find a User by email or normalised Ghana phone number.
     */
    private function findUserByIdentifier(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        $digits = preg_replace('/\D/', '', $identifier);
        $phone = str_starts_with($digits, '233') ? $digits : '233'.ltrim($digits, '0');

        return User::where('phone', $phone)->orWhere('phone', $identifier)->first();
    }

    /**
     * Mask an email for display, e.g. "jane.doe@gmail.com" → "ja••••@gmail.com".
     */
    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === '') {
            return $email;
        }

        $visible = mb_substr($name, 0, min(2, mb_strlen($name)));

        return $visible.'••••@'.$domain;
    }

    /**
     * Mask a phone number for display, keeping the last 3 digits.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last = mb_substr($digits, -3);

        return '••• ••• '.$last;
    }
}
