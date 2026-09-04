<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\Models\Category;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiProvider;

/**
 * FR-10 — repository search reaches into document content, not just the
 * title, and a natural-language query is parsed by the AI layer into the
 * same filters (with a plain-search fallback when the layer is off).
 */
class ContentSearchTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function doc(array $attributes): Document
    {
        return $this->createDocument('user@example.test', $attributes);
    }

    public function test_search_matches_text_inside_the_document(): void
    {
        $hit = $this->doc([
            'title' => 'Campus works programme',
            'extracted_text' => 'The photovoltaic array procurement schedule for the science building extension.',
        ]);
        $miss = $this->doc(['title' => 'Staff away day', 'extracted_text' => 'Team building agenda and catering notes.']);

        $refs = collect($this->asOsmAdmin()->getJson('/api/repository/documents?q=photovoltaic')->json('data'))
            ->pluck('ref');

        $this->assertContains($hit->tracking_no, $refs);
        $this->assertNotContains($miss->tracking_no, $refs);
    }

    public function test_search_still_matches_the_title_and_tracking_number(): void
    {
        $doc = $this->doc(['title' => 'Rankings submission QS 2027', 'extracted_text' => 'nothing relevant here']);

        $byTitle = collect($this->asOsmAdmin()->getJson('/api/repository/documents?q=Rankings')->json('data'))->pluck('ref');
        $byRef = collect($this->asOsmAdmin()->getJson('/api/repository/documents?q='.$doc->tracking_no)->json('data'))->pluck('ref');

        $this->assertContains($doc->tracking_no, $byTitle);
        $this->assertContains($doc->tracking_no, $byRef);
    }

    public function test_natural_language_search_without_ai_is_a_plain_content_search(): void
    {
        $hit = $this->doc(['extracted_text' => 'Quarterly procurement variance analysis for infrastructure works.']);

        $body = $this->asOsmAdmin()->postJson('/api/repository/search', [
            'query' => 'anything mentioning procurement variance',
        ])->assertOk()->json();

        $this->assertFalse($body['ai']);
        $this->assertContains($hit->tracking_no, collect($body['results']['data'])->pluck('ref'));
    }

    public function test_natural_language_search_applies_the_ai_filters(): void
    {
        $categoryName = Category::query()->value('category_name');
        $wanted = $this->doc([
            'extracted_text' => 'Board approval of the 2027 capital budget.',
        ]);
        $wanted->update(['status' => 'approved', 'category_id' => Category::where('category_name', $categoryName)->value('id')]);

        $pendingOne = $this->doc(['extracted_text' => 'Board approval of the 2027 capital budget.']);   // still pending

        $fake = new FakeAiProvider;
        $fake->searchFilters = ['q' => 'capital budget', 'category' => $categoryName, 'status' => 'approved'];
        $this->app->instance(AiProvider::class, $fake);

        $body = $this->asOsmAdmin()->postJson('/api/repository/search', [
            'query' => 'approved '.$categoryName.' documents about the capital budget',
        ])->assertOk()->json();

        $this->assertTrue($body['ai']);
        $this->assertSame('approved', $body['interpreted']['status']);
        $this->assertArrayHasKey('category_id', $body['interpreted']);

        $refs = collect($body['results']['data'])->pluck('ref');
        $this->assertContains($wanted->tracking_no, $refs);
        $this->assertNotContains($pendingOne->tracking_no, $refs);
    }

    public function test_the_search_endpoint_is_admin_only(): void
    {
        $this->asUser()->postJson('/api/repository/search', ['query' => 'anything'])->assertForbidden();
    }
}
