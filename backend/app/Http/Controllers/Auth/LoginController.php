<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * POST /api/login
     * Validates credentials, issues an API token, and tells the
     * frontend which dashboard the user's role belongs on.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Token API — verify the password directly rather than through the
        // session guard (no session is created, and Auth::attempt() would
        // couple this to whichever guard is currently the default).
        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            AuditLog::record($user?->id, 'login_failed', "Failed sign-in for {$credentials['email']}.");
            throw ValidationException::withMessages([
                'email' => 'Those credentials don\'t match our records.',
            ]);
        }

        if (! $user->is_active) {
            AuditLog::record($user->id, 'login_denied', 'Sign-in blocked: account deactivated.', User::class, $user->id);
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your system admin.',
            ]);
        }

        if (SystemSetting::current()->maintenance_mode && $user->role !== User::ROLE_SYSTEM_ADMIN) {
            AuditLog::record($user->id, 'login_denied', 'Sign-in blocked: maintenance mode.', User::class, $user->id);
            throw ValidationException::withMessages([
                'email' => 'The system is temporarily under maintenance. Please try again later.',
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        AuditLog::record($user->id, 'login', 'Signed in.', User::class, $user->id);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'redirect' => $user->dashboardRoute(),
        ]);
    }

    /**
     * POST /api/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        AuditLog::record($user->id, 'logout', 'Signed out.', User::class, $user->id);

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * GET /api/me
     * Lets the frontend rehydrate auth state on refresh.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            ...$this->userPayload($user),
            'redirect' => $user->dashboardRoute(),
        ]);
    }

    /**
     * Keep the JSON key as `name` (not `full_name`) so the existing
     * frontend keeps working without changes, even though the DB
     * column now matches the ERD's `full_name`.
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'office_id' => $user->office_id,
        ];
    }
}
