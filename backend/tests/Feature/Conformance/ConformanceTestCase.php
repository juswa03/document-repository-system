<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\LookupDataSeeder;
use Database\Seeders\RoleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base case for the conformance suite.
 *
 * Each test maps to a verification procedure in
 * docs/conformance-audit-2026-09-01.md (Task F). Tests assert the
 * TARGET behaviour from the process-flow document, so a test that is
 * red today turns green when the remediation phase that owns it lands
 * (docs/remediation-plan.md). Tests gated on a Phase 0 owner decision
 * are marked incomplete rather than guessed.
 */
abstract class ConformanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The sanctum guard authenticating a request calls Auth::shouldUse('sanctum');
        // clear cached guard resolution so a later Auth::attempt() (login) uses
        // the session guard again.
        Auth::forgetGuards();

        $this->seed([
            LookupDataSeeder::class,
            RoleUserSeeder::class,
        ]);
    }

    /**
     * Authenticate the following requests as a seeded account, via
     * Sanctum::actingAs. Safe to call again mid-test to switch user
     * (e.g. upload as the uploader, then review as the OSM admin) —
     * unlike stacking real bearer tokens, which the test guard caches.
     * The /api/login endpoint itself is covered by LoginEndpointTest.
     */
    protected function actingAsEmail(string $email): static
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());

        return $this;
    }

    protected function asUser(): static
    {
        return $this->actingAsEmail('user@example.test');
    }

    protected function asOsmAdmin(): static
    {
        return $this->actingAsEmail('osm.admin@example.test');
    }

    protected function asSystemAdmin(): static
    {
        return $this->actingAsEmail('system.admin@example.test');
    }

    protected function loginRequest(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/login', compact('email', 'password'));
    }

    protected function userId(string $email): int
    {
        return User::where('email', $email)->value('id');
    }

    protected function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /**
     * Every completeness-checklist item for a kind confirmed (Phase 4.3 /
     * PF-09). Spread into a POST /api/osm-admin/reviews body whenever the
     * decision is `approved`.
     *
     * @return array<string, bool>
     */
    protected function completeChecklist(string $kind = 'document'): array
    {
        return collect(config("review.checklists.{$kind}", []))
            ->mapWithKeys(fn (array $item) => [$item['key'] => true])
            ->all();
    }

    /**
     * A valid seeded category id. Never hard-code an id: MySQL keeps the
     * auto-increment counter across RefreshDatabase rollbacks, so the
     * seeded rows are 1..10 in the first test, 11..20 in the next, etc.
     */
    protected function categoryId(): int
    {
        return Category::query()->value('id');
    }

    /**
     * A complete, valid document-upload payload (DR-01…DR-10 + file).
     * Pass overrides to change or (with null) drop individual fields.
     */
    protected function documentPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'title' => 'Board minutes',
            'document_type' => 'minutes',
            'document_date' => now()->subDays(3)->toDateString(),
            'reporting_period' => 'Q3 2026',
            'access_level' => 'internal',
            'keywords' => 'board, minutes, governance',
            'description' => 'Minutes of the Q3 2026 board meeting: strategic priorities and approvals.',
            'category_id' => $this->categoryId(),
            'file' => \Illuminate\Http\UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf'),
        ], $overrides);

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Persist a Document row with a fake file on its private disk,
     * WITHOUT going through the upload endpoint — keeps the test's auth
     * state pristine for a following unauthenticated request. Requires
     * Storage::fake(Document::DISK) in the test.
     */
    protected function createDocument(string $ownerEmail = 'user@example.test', array $attributes = []): Document
    {
        $owner = User::where('email', $ownerEmail)->firstOrFail();
        $path = 'documents/'.\Illuminate\Support\Str::random(40).'.pdf';
        Storage::disk(Document::DISK)->put($path, '%PDF-1.4 test');

        return Document::create([
            'tracking_no' => 'TEST-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
            'title' => 'Fixture document',
            'document_type' => 'report',
            'document_date' => now()->subWeek()->toDateString(),
            'reporting_period' => 'FY 2026',
            'access_level' => 'internal',
            ...$attributes,
            'keywords' => 'fixture',
            'description' => 'Fixture document created directly for a test scenario.',
            'category_id' => $this->categoryId(),
            'uploaded_by' => $owner->id,
            'office_id' => $owner->office_id,
            'file_path' => $path,
            'file_format' => 'pdf',
            'file_size' => 12,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }
}
