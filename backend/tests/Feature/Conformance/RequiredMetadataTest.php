<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The documented minimum metadata set. Eight of the ten required fields
 * are typed into the form and enforced by documentMetadataRules(); the
 * other two are not typed at all:
 *
 *   Source office / unit — taken from the uploader's account
 *   Document status      — assigned by the workflow, never chosen
 *
 * These tests cover the two that are easy to get wrong precisely because
 * no form field represents them.
 */
class RequiredMetadataTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    /** Every field the spec marks required, as validation keys. */
    private const REQUIRED = [
        'title',
        'document_type',
        'category_id',
        'document_date',
        'reporting_period',
        'access_level',
        'keywords',
        'description',
    ];

    #[Test]
    public function each_required_field_is_individually_enforced(): void
    {
        foreach (self::REQUIRED as $field) {
            // A complete payload minus exactly one field.
            $payload = $this->documentPayload([$field => null]);

            $this->asUser()
                ->postJson('/api/dashboard/documents', $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertSame(0, Document::count(), 'No incomplete payload may be stored.');
    }

    /* ---- Source office / unit ---- */

    #[Test]
    public function an_account_with_no_office_cannot_submit(): void
    {
        // users.office_id is nullable but documents.office_id is not, so
        // without a guard this fails as a raw integrity error instead of
        // something the person can act on.
        $officeless = User::factory()->create([
            'role' => User::ROLE_USER,
            'office_id' => null,
        ]);

        $this->actingAsEmail($officeless->email)
            ->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);

        $this->assertSame(0, Document::count());
    }

    #[Test]
    public function an_officeless_draft_cannot_be_submitted_either(): void
    {
        $officeless = User::factory()->create([
            'role' => User::ROLE_USER,
            'office_id' => null,
        ]);

        $draftId = $this->actingAsEmail($officeless->email)
            ->postJson('/api/dashboard/documents/draft', ['title' => 'Started without an office'])
            ->assertCreated()->json('id');

        $this->actingAsEmail($officeless->email)
            ->postJson("/api/dashboard/documents/{$draftId}/submit", [
                'category_id' => $this->categoryId(),
                'document_type' => 'report',
                'document_date' => now()->subDay()->toDateString(),
                'reporting_period' => 'Q3 2026',
                'access_level' => 'internal',
                'keywords' => 'test, draft',
                'description' => 'A draft written by an account with no office assigned to it.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);

        $this->assertSame(
            Document::STATUS_DRAFT,
            Document::find($draftId)->status,
        );
    }

    #[Test]
    public function the_source_office_is_recorded_and_returned(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $uploaderOffice = $this->user('user@example.test')->office;

        // Stored from the account, not typed.
        $this->assertSame($uploaderOffice->id, Document::find($id)->office_id);

        // And surfaced, distinctly from the destination office.
        $mine = collect($this->asUser()->getJson('/api/dashboard/submissions')->json())
            ->firstWhere('id', $id);

        $this->assertSame($uploaderOffice->id, $mine['office_id']);
        $this->assertSame($uploaderOffice->office_name, $mine['source_office']);
        $this->assertArrayHasKey('target_office_id', $mine);
    }

    /* ---- Document status ---- */

    #[Test]
    public function status_is_assigned_by_the_workflow_and_cannot_be_chosen(): void
    {
        // Even when a status is supplied, the workflow's own value wins —
        // an uploader must not be able to self-declare "approved".
        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload([
            'status' => 'approved',
        ]))->assertCreated()->json('id');

        $this->assertSame('pending', Document::find($id)->status);
    }

    /* ---- System-generated metadata ---- */

    #[Test]
    public function the_system_generated_fields_are_all_populated_on_upload(): void
    {
        $before = now()->subSecond();

        $id = $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload())
            ->assertCreated()->json('id');

        $document = Document::find($id);

        $this->assertNotNull($document->tracking_no, 'Tracking number is system-generated.');
        $this->assertTrue($document->submitted_at->greaterThanOrEqualTo($before), 'Date uploaded is system-generated.');
        $this->assertSame($this->userId('user@example.test'), $document->uploaded_by, 'Uploader comes from the account.');
        $this->assertSame('pdf', $document->file_format, 'File format is system-detected.');
        $this->assertGreaterThan(0, $document->file_size, 'File size is system-detected.');
        $this->assertSame(1, $document->version_number, 'Version number starts at 1.');

        // Audit trail is system-generated.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_uploaded',
            'subject_id' => $id,
        ]);
    }
}
