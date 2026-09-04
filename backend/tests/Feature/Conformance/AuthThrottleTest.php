<?php

namespace Tests\Feature\Conformance;

/**
 * Task F-14 — the authentication endpoints had no rate limiting.
 *
 * GREEN after remediation Phase 1.4: the `auth` throttle (5/min per
 * email+IP, 20/min per IP) guards /login, /forgot-password and
 * /reset-password. Anchor: D-6, E-add-6.
 */
class AuthThrottleTest extends ConformanceTestCase
{
    private function attemptLogin(string $email, string $password = 'wrong-password'): int
    {
        return $this->postJson('/api/login', compact('email', 'password'))->getStatusCode();
    }

    public function test_repeated_failed_logins_are_eventually_throttled(): void
    {
        $status = 0;

        for ($i = 0; $i < 12; $i++) {
            $status = $this->attemptLogin('user@example.test');
            if ($status === 429) {
                break;
            }
        }

        $this->assertSame(429, $status, 'Brute-force login attempts should be rate limited (HTTP 429).');
    }

    public function test_throttling_is_scoped_per_email_not_global(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->attemptLogin('victim@example.test');
        }

        // A different account from the same client is not locked out by
        // the victim's failed attempts.
        $this->assertNotSame(
            429,
            $this->attemptLogin('user@example.test', 'password'),
            'Failed attempts against one email must not lock out another.'
        );
    }
}
