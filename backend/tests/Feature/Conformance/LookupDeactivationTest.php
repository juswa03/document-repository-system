<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use App\Models\RequestType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 16 — categories, offices and request types can be deactivated
 * (not just created / edited). A deactivated lookup stays on existing
 * records but disappears from the submission forms and can't be chosen
 * for a new submission.
 */
class LookupDeactivationTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    public function test_a_deactivated_category_drops_out_of_the_dropdown_list_but_not_the_admin_list(): void
    {
        $cat = Category::query()->firstOrFail();

        $this->asSystemAdmin()->patchJson("/api/admin/categories/{$cat->id}", ['is_active' => false])
            ->assertOk();

        $active = collect($this->asUser()->getJson('/api/categories')->json())->pluck('id');
        $all = collect($this->asSystemAdmin()->getJson('/api/categories?all=1')->json())->pluck('id');

        $this->assertNotContains($cat->id, $active);
        $this->assertContains($cat->id, $all);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category_deactivated']);
    }

    public function test_reactivating_brings_it_back(): void
    {
        $cat = Category::query()->firstOrFail();
        $cat->update(['is_active' => false]);

        $this->asSystemAdmin()->patchJson("/api/admin/categories/{$cat->id}", ['is_active' => true])->assertOk();

        $this->assertContains(
            $cat->id,
            collect($this->asUser()->getJson('/api/categories')->json())->pluck('id'),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'category_reactivated']);
    }

    public function test_a_new_submission_cannot_use_a_deactivated_category(): void
    {
        $cat = Category::query()->firstOrFail();
        $cat->update(['is_active' => false]);

        $this->asUser()->postJson('/api/dashboard/documents', $this->documentPayload(['category_id' => $cat->id]))
            ->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_an_existing_document_can_still_be_resubmitted_after_its_category_is_deactivated(): void
    {
        $doc = $this->createDocument();
        $doc->update(['status' => 'revision']);
        Category::whereKey($doc->category_id)->update(['is_active' => false]);

        $this->asUser()->postJson("/api/dashboard/documents/{$doc->id}/resubmit", [
            'file' => UploadedFile::fake()->create('v2.pdf', 6, 'application/pdf'),
        ])->assertOk();
    }

    public function test_a_deactivated_request_type_cannot_start_a_new_request(): void
    {
        $type = RequestType::query()->firstOrFail();
        $type->update(['is_active' => false]);

        $this->asUser()->postJson('/api/dashboard/requests', [
            'request_type_id' => $type->id,
            'title' => 'Travel authority for the regional forum',
            'description' => 'Attendance at the regional strategy forum, three days, one delegate.',
            'needed_by' => now()->addWeeks(3)->toDateString(),
            'document_type' => 'memo',
            'document_date' => now()->toDateString(),
            'reporting_period' => 'Q3 2026',
            'access_level' => 'internal',
            'keywords' => 'travel, forum',
        ])->assertStatus(422)->assertJsonValidationErrors('request_type_id');
    }

    public function test_only_a_system_admin_can_deactivate_a_lookup(): void
    {
        $cat = Category::query()->firstOrFail();
        $this->asOsmAdmin()->patchJson("/api/admin/categories/{$cat->id}", ['is_active' => false])->assertForbidden();
    }
}
