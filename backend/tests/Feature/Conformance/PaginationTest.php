<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 18 — the list endpoints that can grow without bound are paged in
 * a consistent {data, meta} envelope; the report table is capped.
 */
class PaginationTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function seedDocuments(int $n, array $attributes = [], string $prefix = 'PG'): void
    {
        $owner = $this->user('user@example.test');
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = array_merge([
                'tracking_no' => $prefix.'-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'title' => "Doc {$i}",
                'document_type' => 'report',
                'document_date' => now()->subDays($i)->toDateString(),
                'reporting_period' => 'FY 2026',
                'access_level' => 'internal',
                'keywords' => 'x',
                'description' => 'Pagination fixture document.',
                'category_id' => $this->categoryId(),
                'uploaded_by' => $owner->id,
                'office_id' => $owner->office_id,
                'file_path' => "documents/pg-{$i}.pdf",
                'file_format' => 'pdf',
                'file_size' => 10,
                'status' => 'pending',
                'retention_status' => 'active',
                'version_number' => 1,
                'submitted_at' => now()->subDays($i),
            ], $attributes);
        }
        Document::insert($rows);
    }

    public function test_the_review_queue_is_paged(): void
    {
        $this->seedDocuments(45);

        $first = $this->asOsmAdmin()->getJson('/api/osm-admin/queue')->assertOk()->json();
        $this->assertArrayHasKey('data', $first);
        $this->assertArrayHasKey('meta', $first);
        $this->assertSame(20, count($first['data']));
        $this->assertSame(45, $first['meta']['total']);
        $this->assertGreaterThanOrEqual(3, $first['meta']['last_page']);

        $page3 = $this->asOsmAdmin()->getJson('/api/osm-admin/queue?page=3')->assertOk()->json();
        $this->assertSame(3, $page3['meta']['current_page']);
        $this->assertNotEmpty($page3['data']);
    }

    public function test_the_queue_can_be_filtered_by_category(): void
    {
        $other = Category::query()->orderByDesc('id')->value('id');
        $this->seedDocuments(5);
        $this->seedDocuments(3, ['category_id' => $other], 'OC');

        $body = $this->asOsmAdmin()->getJson("/api/osm-admin/queue?category_id={$other}")->assertOk()->json();

        $this->assertSame(3, $body['meta']['total']);
    }

    public function test_manage_users_is_paged_and_all_returns_the_flat_list(): void
    {
        User::factory()->count(0); // no factory dependency
        for ($i = 0; $i < 30; $i++) {
            User::create([
                'full_name' => "Person {$i}",
                'email' => "p{$i}@example.test",
                'role' => User::ROLE_USER,
                'is_active' => true,
                'password' => bcrypt('x'),
            ]);
        }

        $paged = $this->asSystemAdmin()->getJson('/api/admin/users')->assertOk()->json();
        $this->assertArrayHasKey('meta', $paged);
        $this->assertSame(25, count($paged['data']));

        $all = $this->asSystemAdmin()->getJson('/api/admin/users?all=1')->assertOk()->json();
        $this->assertArrayNotHasKey('meta', $all);
        $this->assertGreaterThan(25, count($all));
    }

    public function test_governance_history_is_paged(): void
    {
        $body = $this->asSystemAdmin()->getJson('/api/admin/governance-reviews')->assertOk()->json();

        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('data', $body['history']);
        $this->assertArrayHasKey('meta', $body['history']);
    }

    public function test_repository_search_returns_the_paged_envelope(): void
    {
        $this->seedDocuments(3);

        $body = $this->asOsmAdmin()->getJson('/api/repository/documents')->assertOk()->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('last_page', $body['meta']);
    }

    public function test_the_report_table_is_capped_but_the_csv_is_not(): void
    {
        config(['performance.report_row_cap' => 3]);
        $this->seedDocuments(10, ['status' => 'approved']);

        $json = $this->asOsmAdmin()->getJson('/api/reports/document-inventory')->assertOk()->json();
        $this->assertTrue($json['truncated']);
        $this->assertSame(3, count($json['rows']));
        $this->assertGreaterThanOrEqual(10, $json['total_rows']);

        $csv = $this->asOsmAdmin()->get('/api/reports/document-inventory?format=csv');
        $csv->assertOk();
        $lines = array_filter(explode("\n", trim($csv->streamedContent())));
        $this->assertGreaterThanOrEqual(11, count($lines)); // header + >=10 rows
    }
}
