<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /** Profile pictures share the documents' private disk, never a public one. */
    public const AVATAR_DISK = 'local';

    private const AVATAR_DIR = 'avatars';

    /**
     * PATCH /api/profile
     * Self-service: any authenticated user can update their own display
     * name and password. Email, role, and office stay admin-managed.
     */
    public function update(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'current_password' => ['required_with:new_password', 'nullable', 'string'],
            'new_password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! empty($data['new_password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'That current password is incorrect.',
                ]);
            }
            $user->password = $data['new_password'];
        }

        $user->full_name = $data['full_name'];
        $user->save();

        AuditLog::record($user->id, 'profile_updated', 'Updated their own profile.', User::class, $user->id);

        return response()->json($this->payload($user));
    }

    /**
     * POST /api/profile/avatar
     * Replace the caller's profile picture. The previous file is deleted
     * so a user swapping photos repeatedly does not accumulate orphans on
     * disk.
     */
    public function storeAvatar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            // 2 MB is generous for a profile photo and keeps the private
            // disk from filling with camera-resolution originals.
            'avatar' => ['required', 'file', 'image', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'avatar.image' => 'That file is not an image.',
            'avatar.mimes' => 'Use a JPG, PNG, or WebP image.',
            'avatar.max' => 'That image is too large — the limit is 2MB.',
        ]);

        $previous = $user->avatar_path;

        $user->avatar_path = $request->file('avatar')->store(self::AVATAR_DIR, self::AVATAR_DISK);
        $user->save();

        if ($previous && $previous !== $user->avatar_path) {
            Storage::disk(self::AVATAR_DISK)->delete($previous);
        }

        AuditLog::record($user->id, 'profile_avatar_updated', 'Updated their profile picture.', User::class, $user->id);

        return response()->json($this->payload($user));
    }

    /**
     * DELETE /api/profile/avatar
     * Remove the picture and fall back to initials.
     */
    public function destroyAvatar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk(self::AVATAR_DISK)->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->save();

            AuditLog::record($user->id, 'profile_avatar_removed', 'Removed their profile picture.', User::class, $user->id);
        }

        return response()->json($this->payload($user));
    }

    /**
     * GET /api/users/{user}/avatar
     * Stream a profile picture. Any authenticated user may see a
     * colleague's photo — names are already visible on queues and review
     * history, so a face adds nothing that is not already disclosed — but
     * an anonymous caller may not.
     */
    public function showAvatar(Request $request, User $user)
    {
        if (! $user->avatar_path) {
            abort(404, 'This user has no profile picture.');
        }

        $disk = Storage::disk(self::AVATAR_DISK);

        if (! $disk->exists($user->avatar_path)) {
            abort(404, 'Profile picture not found on disk.');
        }

        // Inline, not a download — this is rendered in an <img>.
        return $disk->response($user->avatar_path);
    }

    /**
     * The user shape the frontend holds in AuthContext. Mirrors
     * LoginController::userPayload so /me, /login and a profile save all
     * return the same fields.
     *
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'office_id' => $user->office_id,
            'avatar_url' => $user->avatarUrl(),
        ];
    }
}
