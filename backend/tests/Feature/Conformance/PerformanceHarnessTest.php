<?php

namespace Tests\Feature\Conformance;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * NFR-06 / NFR-08 — the response-time targets are placeholder numbers in
 * config/performance.php, and `documents:benchmark` measures the hot read
 * paths against a seeded volume so they can be verified on real
 * hardware. This checks the harness works and stays wired; the timings
 * themselves are environment-specific and asserted generously here.
 */
class PerformanceHarnessTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    private function operations(): array
    {
        return [
            'repository_metadata_search', 'repository_content_search', 'review_queue',
            'dashboard_stats', 'report_document_inventory', 'document_lookup_by_tracking_no',
        ];
    }

    public function test_every_hot_path_has_a_configured_target(): void
    {
        $targets = config('performance.targets_ms');

        foreach ($this->operations() as $op) {
            $this->assertArrayHasKey($op, $targets, "No performance target for {$op}");
            $this->assertIsInt($targets[$op]);
        }
    }

    public function test_the_benchmark_seeds_measures_and_cleans_up(): void
    {
        // Generous targets so the pass/fail exit code is deterministic in CI.
        config(['performance.targets_ms' => array_fill_keys($this->operations(), 60000)]);

        $this->artisan('documents:benchmark --seed --rows=40 --iterations=2')
            ->assertExitCode(0);

        $this->assertGreaterThanOrEqual(40, Document::where('tracking_no', 'like', 'PERF-%')->count());

        $this->artisan('documents:benchmark --cleanup')->assertExitCode(0);
        $this->assertSame(0, Document::where('tracking_no', 'like', 'PERF-%')->count());
    }

    public function test_a_missed_target_fails_the_command(): void
    {
        config(['performance.targets_ms' => array_fill_keys($this->operations(), 0)]);   // impossible

        $this->artisan('documents:benchmark --seed --rows=20 --iterations=2')
            ->assertExitCode(1);

        $this->artisan('documents:benchmark --cleanup');
    }
}
