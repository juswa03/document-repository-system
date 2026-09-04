<?php

namespace App\Models;

use App\Authorization\Capability;
use App\Authorization\RoleMatrix;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Recognized system roles.
     * Keep this list in sync with the `role` enum in the users migration.
     */
    public const ROLE_SYSTEM_ADMIN = 'system_admin';
    public const ROLE_OSM_ADMIN = 'osm_admin';
    public const ROLE_USER = 'user';

    public const ROLES = [self::ROLE_USER, self::ROLE_OSM_ADMIN, self::ROLE_SYSTEM_ADMIN];

    protected $fillable = [
        'full_name',
        'email',
        'password',
        'role',
        'office_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Does this user's role hold the given capability? (decision 0.2 —
     * see App\Authorization\RoleMatrix / docs/role-permission-matrix.md.)
     * Also registered as Laravel Gates in AppServiceProvider, so
     * `$user->can('review.approve')` works too.
     */
    public function hasCapability(Capability|string $capability): bool
    {
        $capability = $capability instanceof Capability
            ? $capability
            : Capability::from($capability);

        return RoleMatrix::roleHas((string) $this->role, $capability);
    }

    /**
     * Where the frontend should send this user after login.
     */
    public function dashboardRoute(): string
    {
        return match ($this->role) {
            self::ROLE_SYSTEM_ADMIN => '/admin',
            self::ROLE_OSM_ADMIN => '/osm-admin',
            default => '/dashboard',
        };
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * Laravel's default sends a notification pointing at a Blade
     * route (`password.reset`), which doesn't exist in this API-only
     * app. Point it at the React SPA instead.
     */
    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $url = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SubmissionRequest::class, 'requested_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function reviewsPerformed(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
