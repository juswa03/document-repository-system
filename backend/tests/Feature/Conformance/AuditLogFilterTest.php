<?php

namespace Tests\Feature\Conformance;

use App\Models\AuditLog;

/**
 * Phase 17 — the audit log can be filtered (action, actor, date range)
 * and exported as CSV. RPT-11 / PF-18.
 */
class AuditLogFilterTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->user('system.admin@example.test');
        $osm = $this->user('osm.admin@example.test');

        AuditLog::record($admin->id, 'settings_updated', 'Changed maintenance mode.');
        AuditLog::record($osm->id, 'review_approved', 'Approved DOC-1.');
        AuditLog::record($osm->id, 'review_approved', 'Approved DOC-2.');
        AuditLog::record(null, 'document_text_extracted', 'Extracted text from DOC-3.');
    }

    public function test_it_filters_by_action(): void
    {
        $rows = $this->asSystemAdmin()->getJson('/api/admin/audit-log?action=review_approved')
            ->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame(['review_approved'], array_values(array_unique(array_column($rows, 'action'))));
    }

    public function test_it_filters_by_actor(): void
    {
        $adminId = $this->userId('system.admin@example.test');

        $rows = $this->asSystemAdmin()->getJson("/api/admin/audit-log?actor_id={$adminId}")
            ->assertOk()->json('data');

        foreach ($rows as $r) {
            $this->assertSame('Systema Reyes', $r['actor']);
        }
    }

    public function test_it_filters_by_date_range(): void
    {
        AuditLog::where('action', 'settings_updated')->update(['created_at' => now()->subYears(2)]);

        $recent = $this->asSystemAdmin()
            ->getJson('/api/admin/audit-log?date_from='.now()->subMonth()->toDateString())
            ->assertOk()->json('data');

        $this->assertNotContains('settings_updated', array_column($recent, 'action'));
    }

    public function test_the_response_lists_the_available_actions_for_the_filter(): void
    {
        $actions = $this->asSystemAdmin()->getJson('/api/admin/audit-log')->assertOk()->json('available_actions');

        $this->assertContains('review_approved', $actions);
        $this->assertContains('document_text_extracted', $actions);
    }

    public function test_it_exports_csv_honouring_the_filters(): void
    {
        $res = $this->asSystemAdmin()->get('/api/admin/audit-log?action=review_approved&format=csv');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('When,Actor,Action,Details,IP', $body);
        $this->assertStringContainsString('review_approved', $body);
        $this->assertStringNotContainsString('settings_updated', $body);
    }

    public function test_only_a_system_admin_can_read_the_audit_log(): void
    {
        $this->asOsmAdmin()->getJson('/api/admin/audit-log')->assertForbidden();
        $this->asUser()->getJson('/api/admin/audit-log')->assertForbidden();
    }
}
