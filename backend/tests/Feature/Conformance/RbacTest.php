<?php

namespace Tests\Feature\Conformance;

use App\Authorization\Capability;
use App\Authorization\RoleMatrix;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Task F-01 / F-03 — role-based access control.
 *
 * Decision 0.2 was ratified as Option B: three roles
 * (user / office_admin / system_admin), no reviewer/approver split.
 * These tests lock in every cell of docs/role-permission-matrix.md —
 * both the capability map (App\Authorization\RoleMatrix) and the routes
 * that enforce it (FR-07 / E-11).
 */
class RbacTest extends ConformanceTestCase
{
    // ---------------------------------------------------------------
    // Capability matrix (decision 0.2 — Option B)
    // ---------------------------------------------------------------

    /** Every role × capability pair, with the expected allow flag. */
    public static function matrixCells(): array
    {
        $cells = [];
        foreach (RoleMatrix::forDisplay()['rows'] as $row) {
            foreach ($row['allowed'] as $role => $allowed) {
                $cells["{$role} → {$row['capability']}"] = [$role, $row['capability'], $allowed];
            }
        }

        return $cells;
    }

    #[DataProvider('matrixCells')]
    public function test_capability_matrix_is_enforced(string $role, string $capability, bool $allowed): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->assertSame(
            $allowed,
            $user->hasCapability($capability),
            "hasCapability('{$capability}') for {$role} should be ".($allowed ? 'true' : 'false'),
        );

        // The same fact via the Gate registered in AppServiceProvider.
        $this->assertSame($allowed, Gate::forUser($user)->allows($capability));
    }

    public function test_the_three_roles_are_exactly_the_documented_set(): void
    {
        $this->assertSame(
            ['user', 'office_admin', 'system_admin'],
            array_keys(RoleMatrix::map()),
        );
        $this->assertSame(User::ROLES, array_keys(RoleMatrix::map()));
    }

    public function test_system_admin_holds_no_document_decision_capability(): void
    {
        $decisionCaps = [
            Capability::ReviewDecide,
            Capability::ReviewApprove,
            Capability::ReviewSetAccessLevel,
            Capability::DisposalApprove,
            Capability::DocumentSubmit,
            Capability::RequestSubmit,
        ];

        foreach ($decisionCaps as $cap) {
            $this->assertFalse(
                RoleMatrix::roleHas(User::ROLE_SYSTEM_ADMIN, $cap),
                "system_admin must not hold {$cap->value}",
            );
        }
    }

    // ---------------------------------------------------------------
    // Route enforcement — denied
    // ---------------------------------------------------------------

    public static function deniedCrossRoleCalls(): array
    {
        return [
            // user is confined to its own submissions
            'user → review queue'          => ['user@example.test', 'getJson', '/api/office-admin/queue'],
            'user → osm stats'             => ['user@example.test', 'getJson', '/api/office-admin/stats'],
            'user → retention overview'    => ['user@example.test', 'getJson', '/api/office-admin/retention'],
            'user → admin users'           => ['user@example.test', 'getJson', '/api/admin/users'],
            'user → role matrix'           => ['user@example.test', 'getJson', '/api/admin/role-matrix'],
            'user → repository'            => ['user@example.test', 'getJson', '/api/repository/documents'],
            'user → reports'              => ['user@example.test', 'getJson', '/api/reports/documents'],

            // office_admin runs review but not the platform
            'office_admin → admin users'      => ['office.admin@example.test', 'getJson', '/api/admin/users'],
            'office_admin → role matrix'      => ['office.admin@example.test', 'getJson', '/api/admin/role-matrix'],
            'office_admin → audit log'        => ['office.admin@example.test', 'getJson', '/api/admin/audit-log'],
            'office_admin → ai settings'      => ['office.admin@example.test', 'getJson', '/api/admin/ai-settings'],
            'office_admin → system settings'  => ['office.admin@example.test', 'getJson', '/api/admin/settings'],

            // system_admin is platform-only — no document decisions, no submitting
            'system_admin → review queue'  => ['system.admin@example.test', 'getJson', '/api/office-admin/queue'],
            'system_admin → osm stats'     => ['system.admin@example.test', 'getJson', '/api/office-admin/stats'],
            'system_admin → retention'     => ['system.admin@example.test', 'getJson', '/api/office-admin/retention'],
            'system_admin → post review'   => ['system.admin@example.test', 'postJson', '/api/office-admin/reviews'],
            'system_admin → own submissions' => ['system.admin@example.test', 'getJson', '/api/dashboard/submissions'],
            'system_admin → submit document' => ['system.admin@example.test', 'postJson', '/api/dashboard/documents'],
        ];
    }

    #[DataProvider('deniedCrossRoleCalls')]
    public function test_cross_role_calls_are_denied(string $email, string $method, string $uri): void
    {
        $this->actingAsEmail($email)->{$method}($uri)->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Route enforcement — allowed (a GET that must NOT 403 for the role)
    // ---------------------------------------------------------------

    public static function allowedRoleCalls(): array
    {
        return [
            'user → own submissions'        => ['user@example.test', '/api/dashboard/submissions'],
            'office_admin → review queue'      => ['office.admin@example.test', '/api/office-admin/queue'],
            'office_admin → retention'         => ['office.admin@example.test', '/api/office-admin/retention'],
            'office_admin → repository'        => ['office.admin@example.test', '/api/repository/documents'],
            'office_admin → reports'           => ['office.admin@example.test', '/api/reports'],
            'system_admin → admin users'    => ['system.admin@example.test', '/api/admin/users'],
            'system_admin → role matrix'    => ['system.admin@example.test', '/api/admin/role-matrix'],
            'system_admin → repository'     => ['system.admin@example.test', '/api/repository/documents'],
            'system_admin → reports'        => ['system.admin@example.test', '/api/reports'],
        ];
    }

    #[DataProvider('allowedRoleCalls')]
    public function test_in_scope_calls_are_permitted(string $email, string $uri): void
    {
        $this->actingAsEmail($email)->getJson($uri)->assertSuccessful();
    }

    public function test_role_matrix_endpoint_returns_the_canonical_table(): void
    {
        $this->asSystemAdmin()->getJson('/api/admin/role-matrix')
            ->assertOk()
            ->assertJsonPath('roles', ['user', 'office_admin', 'system_admin'])
            ->assertJsonPath('rows.0.capability', Capability::cases()[0]->value)
            ->assertJsonCount(count(Capability::cases()), 'rows');
    }

    // ---------------------------------------------------------------
    // Access level is layered on top of role (system_admin not need-to-know)
    // ---------------------------------------------------------------

    public function test_system_admin_cannot_download_a_restricted_document(): void
    {
        \Illuminate\Support\Facades\Storage::fake(\App\Models\Document::DISK);
        $doc = $this->createDocument('user@example.test', [
            'access_level' => 'restricted',
        ]);

        $this->asSystemAdmin()->getJson("/api/documents/{$doc->id}/file")->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Pre-existing guards (kept)
    // ---------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/repository/documents')->assertUnauthorized();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/dashboard/documents')->assertUnauthorized();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->postJson('/api/login', [
            'email' => 'user@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
}
