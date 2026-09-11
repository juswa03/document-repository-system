<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;

/**
 * FR-02 — the seeded document categories are the ten OSM categories
 * from §A of the process-flow document (decision 0.6).
 */
class LookupDataTest extends ConformanceTestCase
{
    public function test_the_ten_documented_categories_are_seeded(): void
    {
        $expected = [
            'STRAT' => 'Strategic Planning Documents',
            'PERF' => 'Performance Monitoring Documents',
            'ACCR' => 'Accreditation and Quality Assurance Documents',
            'RANK' => 'Rankings and Internationalization Documents',
            'GOV' => 'Governance Documents',
            'INFRA' => 'Infrastructure and Development Planning Documents',
            'COMP' => 'Compliance and Regulatory Documents',
            'TMPL' => 'Templates and Controlled Forms',
            'ADMIN' => 'Administrative Documents',
            'ARCH' => 'Archived Documents',
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
            ->assertJsonCount(10)
            ->assertJsonFragment(['category_code' => 'ACCR']);
    }
}
