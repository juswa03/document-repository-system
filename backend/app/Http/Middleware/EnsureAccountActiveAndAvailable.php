<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks, on every authenticated request, the two things the login
 * flow checks once: the account is still active, and (for non-admins)
 * the system is not in maintenance. Without this an issued token keeps
 * full access after the account is deactivated or maintenance is turned
 * on (audit D-4 / D-5).
 *
 * Runs after `auth:sanctum`, so `$request->user()` is populated.
 */
class EnsureAccountActiveAndAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fresh read — never trust a possibly-stale in-memory model.
        $isActive = User::whereKey($request->user()->getAuthIdentifier())->value('is_active');

        if (! $isActive) {
            return response()->json(['message' => 'Your account has been deactivated. Contact your system admin.'], 401);
        }

        if (SystemSetting::current()->maintenance_mode && $request->user()->role !== User::ROLE_SYSTEM_ADMIN) {
            return response()->json(['message' => 'The system is temporarily under maintenance. Please try again later.'], 503);
        }

        return $next($request);
    }
}
