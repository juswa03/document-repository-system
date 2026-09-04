<?php

namespace Tests\Feature\Conformance;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Task F-04 / F-05 — audit trail coverage and tamper-resistance.
 *
 * GREEN after remediation Phase 1.2:
 *  - upload, download, resubmit, login/logout and settings changes all
 *    write an audit row (PF-18, BR-06);
 *  - AuditLog::record() always writes — there is no on/off switch
 *    (D-2, E-15).
 */
class AuditTrailTest extends ConformanceTestCase
{
    private function uploadDocument(): int
    {
        return $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload(['title' => 'Quarterly plan']))
            ->assertCreated()->json('id');
    }

    public function test_login_is_audited(): void
    {
        $this->postJson('/api/login', ['email' => 'user@example.test', 'password' => 'password'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'login']);
    }

    public function test_document_upload_is_audited(): void
    {
        Storage::fake(Document::DISK);
        $id = $this->uploadDocument();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_uploaded',
            'subject_type' => Document::class,
            'subject_id' => $id,
        ]);
    }

    public function test_document_download_is_audited(): void
    {
        Storage::fake(Document::DISK);
        $doc = $this->createDocument('user@example.test');
        AuditLog::query()->delete();

        $this->asUser()->get("/api/documents/{$doc->id}/file")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_downloaded',
            'subject_id' => $doc->id,
        ]);
    }

    public function test_audit_rows_capture_the_request_ip(): void
    {
        Storage::fake(Document::DISK);
        $this->uploadDocument();

        $this->assertNotNull(
            AuditLog::where('action', 'document_uploaded')->value('ip_address'),
            'Audit rows should record the request IP.'
        );
    }

    public function test_audit_logging_cannot_be_switched_off_for_a_mandated_event(): void
    {
        Storage::fake(Document::DISK);
        SystemSetting::current()->update(['audit_logging_enabled' => false]);

        $id = $this->uploadDocument();

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist(),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'review_approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_uploaded']);
    }

    public function test_a_settings_change_is_audited_with_before_and_after(): void
    {
        $this->asSystemAdmin()->patchJson('/api/admin/settings', ['maintenance_mode' => true])->assertOk();

        $row = AuditLog::where('action', 'settings_updated')->firstOrFail();
        $this->assertSame(false, $row->properties['before']['maintenance_mode']);
        $this->assertSame(true, $row->properties['after']['maintenance_mode']);
    }
}
