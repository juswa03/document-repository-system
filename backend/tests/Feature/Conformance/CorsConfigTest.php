<?php

namespace Tests\Feature\Conformance;

/**
 * Phase 38 regression guard. A real browser walkthrough (curl/PHPUnit
 * can't see this — CORS is enforced client-side) found that
 * /broadcasting/auth had no Access-Control-Allow-Origin header: the
 * framework's built-in CORS defaults only cover api/* and
 * sanctum/csrf-cookie, and Phase 35 added a same-origin-looking but
 * actually cross-origin (5173 -> 8000) endpoint without extending them.
 * config/cors.php now adds broadcasting/* explicitly.
 */
class CorsConfigTest extends ConformanceTestCase
{
    private function preflight(string $uri)
    {
        return $this->call('OPTIONS', $uri, [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
    }

    public function test_the_broadcasting_auth_endpoint_allows_the_spa_origin(): void
    {
        $this->preflight('/broadcasting/auth')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_the_api_surface_still_allows_the_spa_origin(): void
    {
        $this->preflight('/api/login')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_an_unlisted_path_gets_no_cors_headers(): void
    {
        // Proves the assertion above is actually discriminating on
        // config('cors.paths') rather than passing unconditionally.
        $this->preflight('/up')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
