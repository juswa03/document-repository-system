<?php

namespace App\Providers;

use App\AI\AiManager;
use App\AI\AiSettings;
use App\AI\Contracts\AiProvider;
use App\Authorization\Capability;
use App\Models\User;
use App\Scanning\ClamAvScanner;
use App\Scanning\Contracts\FileScanner;
use App\Scanning\NullScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The active AI provider (§F). Bound, not singleton, so a change
        // to the admin-managed settings takes effect on the next request.
        // Resolves to NullProvider whenever the layer is off or has no
        // API key.
        $this->app->bind(AiProvider::class, fn () => AiManager::resolve(AiSettings::fromCurrent()));

        // Upload malware scanner (PF-03). Resolves to a no-op NullScanner
        // unless SCAN_DRIVER=clamav and a clamd is reachable.
        $this->app->bind(FileScanner::class, function () {
            $config = config('scanning');

            return match ($config['driver']) {
                'clamav' => new ClamAvScanner($config['clamav']),
                default => new NullScanner(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Role → permission matrix (decision 0.2, Option B). Register
        // every capability as a Gate backed by the single-source
        // RoleMatrix, so controllers/policies can call
        // `$this->authorize('review.approve')` or `$user->can(...)`
        // instead of hard-coding role literals. The `role:` route
        // middleware still guards the coarse route groups; these gates
        // are for finer, in-controller checks.
        foreach (Capability::cases() as $capability) {
            Gate::define(
                $capability->value,
                fn (User $user) => $user->hasCapability($capability),
            );
        }

        // Rate limit for the unauthenticated auth endpoints (login,
        // forgot-password, reset-password). Per email+IP so one caller
        // can't lock a victim out, plus a looser per-IP cap against a
        // caller rotating email addresses. Audit D-6 / E-add-6.
        RateLimiter::for('auth', function (Request $request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by(mb_strtolower($email).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
