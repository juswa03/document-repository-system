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
     * Optional filters: ?q= (name/email), ?role=, ?status=active|inactive.
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(['system_admin', 'osm_admin', 'user'])],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $query = User::with('office')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($qq) => $qq->where('full_name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('is_active', $v === 'active'))
            ->orderBy('full_name');

        // ?all=1 — the flat list the overview donut and the audit-log
        // actor filter need. Default is paginated.
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        return response()->json($this->paged($query->paginate(25)->withQueryString()));
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
     * Edits basic fields, toggles is_active, and/or sets a new password.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(['system_admin', 'osm_admin', 'user'])],
            'office_id' => ['sometimes', 'nullable', 'exists:offices,id'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        // 'boolean' validation accepts the strings "false"/"0"; the model
        // cast would store those as true. Normalise before use.
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        // An admin must not be able to deactivate their own live account.
        $isSelf = $user->id === $request->user()->id;
        if ($isSelf && array_key_exists('is_active', $data) && ! $data['is_active']) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        // The platform must always keep at least one active system admin —
        // covers demoting or deactivating the last one (yourself included).
        if ($this->wouldOrphanAdmins($user, $data)) {
            return response()->json([
                'message' => 'This is the last active system admin — give another active account the system-admin role first.',
            ], 422);
        }

        $newPassword = $data['password'] ?? null;
        unset($data['password']);

        $wasActive = $user->is_active;
        $user->update($data);

        if ($newPassword !== null) {
            // The model's `hashed` cast bcrypts this on save.
            $user->forceFill(['password' => $newPassword])->save();
            // End every current session — the old password is gone.
            $user->tokens()->delete();

            AuditLog::record(
                $request->user()->id,
                'user_password_reset',
                "Set a new password for {$user->full_name}.",
                User::class,
                $user->id
            );
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] !== $wasActive) {
            if (! $data['is_active']) {
                // Revoke every issued token so the session ends now, not
                // whenever the token would have expired.
                $user->tokens()->delete();
            }

            AuditLog::record(
                $request->user()->id,
                $data['is_active'] ? 'user_reactivated' : 'user_deactivated',
                ($data['is_active'] ? 'Reactivated' : 'Deactivated')." user {$user->full_name}.",
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

    /**
     * Would applying $data to $user leave the system with no active
     * system admin? True only when $user is currently an active system
     * admin, the change drops that (role or is_active), and no other
     * active system admin exists.
     *
     * @param  array<string, mixed>  $data
     */
    private function wouldOrphanAdmins(User $user, array $data): bool
    {
        $isActiveAdmin = $user->is_active && $user->role === 'system_admin';
        if (! $isActiveAdmin) {
            return false;
        }

        $staysAdmin = ($data['role'] ?? $user->role) === 'system_admin';
        $staysActive = array_key_exists('is_active', $data) ? $data['is_active'] : $user->is_active;
        if ($staysAdmin && $staysActive) {
            return false;
        }

        return ! User::where('id', '!=', $user->id)
            ->where('role', 'system_admin')
            ->where('is_active', true)
            ->exists();
    }
}
