<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * GET /api/admin/users
     */
    public function index()
    {
        return response()->json(
            User::with('office')->orderBy('full_name')->get()
        );
    }

    /**
     * POST /api/admin/users
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::in(['system_admin', 'osm_admin', 'user'])],
            'office_id' => ['nullable', 'exists:offices,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        // $data['password'] is the raw value — the User model's `hashed`
        // cast hashes it on save. Do NOT Hash::make() here as well, or it
        // gets bcrypt'd twice and the account can never log in.
        $user = User::create([
            ...$data,
            'is_active' => true,
        ]);

        AuditLog::record(
            $request->user()->id,
            'user_created',
            "Created user {$user->full_name} ({$user->email}) as {$user->role}.",
            User::class,
            $user->id
        );

        return response()->json($user, 201);
    }

    /**
     * PATCH /api/admin/users/{user}
     * Edits basic fields and/or toggles is_active.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(['system_admin', 'osm_admin', 'user'])],
            'office_id' => ['sometimes', 'nullable', 'exists:offices,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // 'boolean' validation accepts the strings "false"/"0"; the model
        // cast would store those as true. Normalise before use.
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $wasActive = $user->is_active;
        $user->update($data);

        if (array_key_exists('is_active', $data) && $data['is_active'] !== $wasActive) {
            if (! $data['is_active']) {
                // Revoke every issued token so the session ends now, not
                // whenever the token would have expired.
                $user->tokens()->delete();
            }

            AuditLog::record(
                $request->user()->id,
                $data['is_active'] ? 'user_reactivated' : 'user_deactivated',
                ($data['is_active'] ? 'Reactivated' : 'Deactivated') . " user {$user->full_name}.",
                User::class,
                $user->id
            );
        } elseif (! empty($data)) {
            AuditLog::record(
                $request->user()->id,
                'user_edited',
                "Edited user {$user->full_name}.",
                User::class,
                $user->id
            );
        }

        return response()->json($user);
    }
}
