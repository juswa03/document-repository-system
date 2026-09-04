<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;

/**
 * FR-02 — the seeded document categories are the nine OSM categories
 * from §A of the process-flow document (decision 0.6).
 */
class LookupDataTest extends ConformanceTestCase
{
    public function test_the_nine_documented_categories_are_seeded(): void
    {
        $expected = [
            'STRAT' => 'Strategic Planning',
            'PERF' => 'Performance Monitoring',
            'ACCR' => 'Accreditation & Quality Assurance',
            'RANK' => 'Rankings & Internationalization',
            'GOV' => 'Governance',
            'INFRA' => 'Infrastructure & Development Planning',
            'COMP' => 'Compliance & Regulatory',
            'TMPL' => 'Templates & Controlled Forms',
            'ADMIN' => 'Administrative',
        ];

        $this->assertEqualsCanonicalizing(
            $expected,
            Category::pluck('category_name', 'category_code')->all(),
        );
    }

    public function test_categories_are_offered_to_authenticated_users(): void
    {
        $this->asUser()->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(9)
            ->assertJsonFragment(['category_code' => 'ACCR']);
    }
}
