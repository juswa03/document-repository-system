<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * PF-06 / AI-03 — an identical re-upload is flagged as a possible
 * duplicate. Phase 5.1 delivers the deterministic core: a SHA-256 of the
 * file bytes, matched within the uploader's own submissions and their
 * office. The flag is advisory (BR-03) — the upload still succeeds.
 */
class DuplicateDetectionTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function upload(User $as, UploadedFile $file): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/dashboard/documents', $this->documentPayload(['file' => $file]));
    }

    /**
     * A fake PDF with real, caller-controlled bytes — `fake()->create()`
     * writes an empty file, so every such upload would hash alike. The
     * `%PDF-1.4` magic keeps the `mimes:pdf` rule satisfied.
     */
    private function pdf(string $marker): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%%EOF\n{$marker}");
    }

    private function userInOffice(string $officeCode): User
    {
        $office = Office::firstOrCreate(
            ['office_code' => $officeCode],
            ['office_name' => "Office {$officeCode}"],
        );

        return User::create([
            'full_name' => "Person {$officeCode}",
            'email' => strtolower($officeCode).'.'.uniqid().'@example.test',
            'role' => User::ROLE_USER,
            'office_id' => $office->id,
            'is_active' => true,
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
    }

    public function test_an_identical_re_upload_by_the_same_user_is_flagged(): void
    {
        $user = $this->user('user@example.test');
        $file = $this->pdf('alpha');

        $first = $this->upload($user, $file)->assertCreated();
        $second = $this->upload($user, $file)->assertCreated();

        $this->assertNull($first->json('duplicate_of'));
        $this->assertSame($first->json('ref'), $second->json('duplicate_of.ref'));
    }

    public function test_the_flag_is_advisory_and_is_recorded_in_the_audit_trail(): void
    {
        $user = $this->user('user@example.test');
        $file = $this->pdf('beta');

        $this->upload($user, $file)->assertCreated();
        $second = $this->upload($user, $file)->assertCreated();   // still 201 — not blocked

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'duplicate_flagged',
            'subject_type' => Document::class,
            'subject_id' => $second->json('id'),
        ]);
    }

    public function test_a_different_file_is_not_flagged(): void
    {
        $user = $this->user('user@example.test');

        $this->upload($user, $this->pdf('one'))->assertCreated();
        $other = $this->upload($user, $this->pdf('two'))->assertCreated();

        $this->assertNull($other->json('duplicate_of'));
    }

    public function test_an_office_mate_re_uploading_the_same_file_is_flagged(): void
    {
        $file = $this->pdf('gamma');
        $mateA = $this->userInOffice('SHARED');
        $mateB = $this->userInOffice('SHARED');   // same office row

        $first = $this->upload($mateA, $file)->assertCreated();
        $second = $this->upload($mateB, $file)->assertCreated();

        $this->assertSame($first->json('ref'), $second->json('duplicate_of.ref'));
    }

    public function test_the_same_bytes_from_a_different_office_are_not_flagged(): void
    {
        $file = $this->pdf('delta');

        $this->upload($this->userInOffice('BR-A'), $file)->assertCreated();
        $elsewhere = $this->upload($this->userInOffice('BR-B'), $file)->assertCreated();

        $this->assertNull($elsewhere->json('duplicate_of'));
    }

    public function test_resubmitting_with_a_replacement_file_updates_the_stored_hash(): void
    {
        $user = $this->user('user@example.test');

        $id = $this->upload($user, $this->pdf('v1'))->assertCreated()->json('id');
        $before = Document::find($id)->content_hash;

        Sanctum::actingAs($this->user('osm.admin@example.test'));
        $this->postJson('/api/osm-admin/reviews', [
            'kind' => 'document', 'id' => $id, 'decision' => 'revision', 'remarks' => 'redo',
        ])->assertCreated();

        Sanctum::actingAs($user);
        $this->postJson("/api/dashboard/documents/{$id}/resubmit", [
            'file' => $this->pdf('v2-different'),
        ])->assertOk();

        $after = Document::find($id)->content_hash;
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
    }
}
