<?php

namespace Tests\Feature\Conformance;

use App\Models\Category;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/**
 * DR-16 / BR-09 — a tracking number is assigned at submission, and it is
 * always unique. Guards the Phase 1.5 fix: the sequence is derived from
 * the highest suffix in use (not COUNT(*)), and a colliding insert
 * retries instead of 500-ing.
 */
class TrackingNumberTest extends ConformanceTestCase
{
    public function test_a_gap_in_the_daily_sequence_does_not_reuse_a_number(): void
    {
        Storage::fake(Document::DISK);

        $user = $this->user('user@example.test');
        $category = Category::query()->firstOrFail();
        $prefix = "{$category->category_code}-{$user->office->office_code}-".now()->format('Ymd').'-';

        // Only "…-005" exists (001–004 were rolled back). A COUNT-based
        // sequence would compute 002 and collide; this must land on 006.
        $this->createDocument()->update(['tracking_no' => $prefix.'005', 'category_id' => $category->id]);

        $ref = $this->asUser()
            ->postJson('/api/dashboard/documents', $this->documentPayload([
                'title' => 'New upload',
                'category_id' => $category->id,
            ]))
            ->assertCreated()->json('ref');

        $this->assertSame($prefix.'006', $ref);
    }

    public function test_every_submission_gets_a_distinct_tracking_number(): void
    {
        Storage::fake(Document::DISK);
        $category = Category::query()->value('id');

        $refs = [];
        for ($i = 0; $i < 4; $i++) {
            $refs[] = $this->asUser()
                ->postJson('/api/dashboard/documents', $this->documentPayload([
                    'title' => "Doc number {$i}",
                    'category_id' => $category,
                ]))
                ->assertCreated()->json('ref');
        }

        $this->assertCount(4, array_unique($refs));
    }
}
