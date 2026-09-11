<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Office;
use App\Models\Review;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Answering a submission with a document already in the repository,
 * instead of uploading a fresh copy.
 *
 * Linking keeps one authoritative record — the document keeps its own
 * tracking number, version history and retention state. The constraint
 * that matters is that linking must not become a side door around the
 * access-grant system: an office admin sees every access level in their
 * office, a plain submitter does not.
 */
class ResponseDocumentTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    /** A pending request from the seeded user, ready to be decided. */
    private function pendingRequest(): int
    {
        return $this->asUser()->postJson('/api/dashboard/requests', [
            'request_type_id' => \App\Models\RequestType::where('type_code', 'SDR')->value('id'),
            'title' => 'Copy of the approved operational plan',
            'description' => 'Requesting the approved operational plan for our own planning work.',
            'needed_by' => now()->addWeeks(2)->toDateString(),
            'access_level' => 'internal',
        ])->assertCreated()->json('id');
    }

    /** An approved, active document held by the reviewer's office. */
    private function repositoryDocument(array $attributes = []): Document
    {
        $document = $this->createDocument('office.admin@example.test', $attributes);

        $document->update([
            'status' => 'approved',
            'retention_status' => 'active',
            'target_office_id' => $this->user('office.admin@example.test')->office_id,
        ] + $attributes);

        return $document->fresh();
    }

    /* ---- The picker ---- */

    #[Test]
    public function the_picker_offers_approved_public_and_internal_documents_of_the_office(): void
    {
        $id = $this->pendingRequest();

        $offered = $this->repositoryDocument(['title' => 'Approved operational plan 2026']);

        $rows = $this->asOfficeAdmin()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}")
            ->assertOk()->json('data');

        $this->assertSame([$offered->id], collect($rows)->pluck('id')->all());
        $this->assertSame('Approved operational plan 2026', $rows[0]['title']);
    }

    #[Test]
    public function the_picker_hides_restricted_and_confidential_documents(): void
    {
        $id = $this->pendingRequest();

        $this->repositoryDocument(['title' => 'Board evaluation', 'access_level' => 'confidential']);
        $this->repositoryDocument(['title' => 'Named list', 'access_level' => 'restricted']);

        $rows = $this->asOfficeAdmin()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}")
            ->assertOk()->json('data');

        $this->assertSame([], $rows, 'Sensitive documents must not be offered as a response.');
    }

    #[Test]
    public function the_picker_hides_documents_that_are_not_approved_and_active(): void
    {
        $id = $this->pendingRequest();

        $pending = $this->repositoryDocument(['title' => 'Still under review']);
        $pending->update(['status' => 'pending']);

        $archived = $this->repositoryDocument(['title' => 'Old copy']);
        $archived->update(['retention_status' => 'archived']);

        $rows = $this->asOfficeAdmin()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}")
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    #[Test]
    public function the_picker_hides_another_offices_documents(): void
    {
        $id = $this->pendingRequest();

        $elsewhere = Office::create(['office_name' => 'Another Unit', 'office_code' => 'OTHR']);
        $foreign = $this->repositoryDocument(['title' => 'Another units plan']);
        $foreign->update(['target_office_id' => $elsewhere->id, 'office_id' => $elsewhere->id]);

        $rows = $this->asOfficeAdmin()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}")
            ->assertOk()->json('data');

        $this->assertSame([], $rows);
    }

    #[Test]
    public function the_picker_can_be_searched(): void
    {
        $id = $this->pendingRequest();

        $wanted = $this->repositoryDocument(['title' => 'Operational plan 2026']);
        $this->repositoryDocument(['title' => 'Procurement schedule']);

        $rows = $this->asOfficeAdmin()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}&q=operational")
            ->assertOk()->json('data');

        $this->assertSame([$wanted->id], collect($rows)->pluck('id')->all());
    }

    #[Test]
    public function a_plain_user_cannot_browse_the_picker(): void
    {
        $id = $this->pendingRequest();

        $this->asUser()
            ->getJson("/api/office-admin/response-documents?kind=request&id={$id}")
            ->assertForbidden();
    }

    /* ---- Linking on approval ---- */

    #[Test]
    public function a_reviewer_can_answer_a_request_with_a_repository_document(): void
    {
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument(['title' => 'Approved operational plan 2026']);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $answer->id,
        ])->assertCreated()
            ->assertJsonPath('response_file.from_repository', true);

        $review = Review::where('request_id', $id)->firstOrFail();
        $this->assertSame($answer->id, $review->response_document_id);

        // Linked, not copied — no second file was written.
        $this->assertNull($review->response_file_path);
    }

    #[Test]
    public function the_submitter_can_download_the_linked_document(): void
    {
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument(['title' => 'Approved operational plan 2026']);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $answer->id,
        ])->assertCreated();

        $review = Review::where('request_id', $id)->firstOrFail();

        $this->asUser()->get("/api/reviews/{$review->id}/response-file")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'review_response_downloaded']);
    }

    #[Test]
    public function someone_else_still_cannot_download_it(): void
    {
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument();

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $answer->id,
        ])->assertCreated();

        $review = Review::where('request_id', $id)->firstOrFail();

        // The submitter-only rule is unchanged by linking.
        $this->asSystemAdmin()->get("/api/reviews/{$review->id}/response-file")->assertForbidden();
    }

    #[Test]
    public function the_submissions_payload_shows_a_linked_response(): void
    {
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument(['title' => 'Approved operational plan 2026']);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $answer->id,
        ])->assertCreated();

        $mine = collect($this->asUser()->getJson('/api/dashboard/submissions')->json())
            ->firstWhere('id', $id);

        $this->assertTrue($mine['response_file']['from_repository']);
        $this->assertSame('Approved operational plan 2026', $mine['response_file']['name']);
        $this->assertSame($answer->tracking_no, $mine['response_file']['ref']);
    }

    /* ---- The guards that matter ---- */

    #[Test]
    public function a_confidential_document_is_refused_even_when_posted_directly(): void
    {
        // The picker hides it, but the id could be posted by hand — the
        // server must not rely on the interface having filtered it.
        $id = $this->pendingRequest();
        $secret = $this->repositoryDocument(['access_level' => 'confidential']);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $secret->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['response_document_id']);

        $this->assertSame('pending', \App\Models\SubmissionRequest::find($id)->status);
    }

    #[Test]
    public function another_offices_document_is_refused_even_when_posted_directly(): void
    {
        $id = $this->pendingRequest();

        $elsewhere = Office::create(['office_name' => 'Another Unit', 'office_code' => 'OTHR']);
        $foreign = $this->repositoryDocument();
        $foreign->update(['target_office_id' => $elsewhere->id, 'office_id' => $elsewhere->id]);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['response_document_id']);
    }

    #[Test]
    public function a_reviewer_cannot_both_upload_and_link(): void
    {
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument();

        $this->asOfficeAdmin()->post('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
            'response_document_id' => $answer->id,
            'response_file' => UploadedFile::fake()->create('letter.pdf', 6, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    #[Test]
    public function a_returned_submission_carries_no_response_document(): void
    {
        // Only an approval hands something over; a revision is a request
        // for more work, not an answer.
        $id = $this->pendingRequest();
        $answer = $this->repositoryDocument();

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request',
            'id' => $id,
            'decision' => 'revision',
            'remarks' => 'Please state which reporting period you need.',
            'response_document_id' => $answer->id,
        ])->assertCreated();

        $this->assertNull(Review::where('request_id', $id)->value('response_document_id'));
    }
}
