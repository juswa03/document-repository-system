<?php

namespace Tests\Feature\Conformance;

use App\LeadTime\Target;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The published lead-time table (decision 0.9). Targets are advisory —
 * nothing blocks — but they must be counted the way the table states
 * them: in WORKING days, excluding weekends and configured holidays.
 */
class LeadTimeTest extends ConformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::DISK);
    }

    /* ---- Working-day arithmetic ---- */

    #[Test]
    public function a_weekend_does_not_count_towards_the_target(): void
    {
        // Friday → Monday is three calendar days but one working day.
        $friday = Carbon::parse('2026-09-04 09:00');   // Friday
        $monday = Carbon::parse('2026-09-07 09:00');   // Monday

        $this->assertSame(3, (int) $friday->diffInDays($monday), 'sanity: 3 calendar days');
        $this->assertSame(1, Target::workingDaysBetween($friday, $monday));
    }

    #[Test]
    public function a_full_week_counts_five_working_days(): void
    {
        $this->assertSame(5, Target::workingDaysBetween(
            Carbon::parse('2026-09-07 09:00'),  // Monday
            Carbon::parse('2026-09-14 09:00'),  // the next Monday
        ));
    }

    #[Test]
    public function a_configured_holiday_does_not_count(): void
    {
        config(['lead_times.holidays' => ['2026-09-09']]);   // a Wednesday

        // Mon → Fri is 4 working days, less the Wednesday holiday.
        $this->assertSame(3, Target::workingDaysBetween(
            Carbon::parse('2026-09-07 09:00'),
            Carbon::parse('2026-09-11 09:00'),
        ));
    }

    #[Test]
    public function the_same_day_is_zero_working_days(): void
    {
        $morning = Carbon::parse('2026-09-07 08:00');
        $evening = Carbon::parse('2026-09-07 18:00');

        $this->assertSame(0, Target::workingDaysBetween($morning, $evening));
    }

    /* ---- The targets themselves ---- */

    #[Test]
    public function a_simple_document_gets_the_two_day_target(): void
    {
        $document = $this->createDocument('user@example.test');
        $document->update(['access_level' => 'internal']);

        $this->assertSame(Target::SIMPLE, Target::complexity($document));
        $this->assertSame(2, Target::reviewDays($document));
    }

    #[Test]
    public function a_sensitive_document_gets_the_five_day_target(): void
    {
        // "Review of complex or sensitive documents — 3-5 working days."
        foreach (['restricted', 'confidential'] as $level) {
            $document = $this->createDocument('user@example.test');
            $document->update(['access_level' => $level]);

            $this->assertSame(Target::COMPLEX, Target::complexity($document));
            $this->assertSame(5, Target::reviewDays($document));
        }
    }

    #[Test]
    public function a_submission_is_not_overdue_until_it_passes_its_target(): void
    {
        $document = $this->createDocument('user@example.test');

        // Submitted Monday; by Wednesday two working days have passed —
        // at target, not over it.
        $document->update([
            'access_level' => 'internal',
            'submitted_at' => Carbon::parse('2026-09-07 09:00'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-09 09:00'));
        $this->assertSame(2, Target::daysInStage($document->fresh()));
        $this->assertFalse(Target::isOverdue($document->fresh()));

        // Thursday — now past the 2-day target.
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00'));
        $this->assertTrue(Target::isOverdue($document->fresh()));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_friday_submission_is_not_overdue_on_monday(): void
    {
        // The bug this guards: counting calendar days made a Friday
        // submission look 3 days old on Monday and wrongly overdue.
        $document = $this->createDocument('user@example.test');
        $document->update([
            'access_level' => 'internal',
            'submitted_at' => Carbon::parse('2026-09-04 09:00'),  // Friday
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-07 09:00'));    // Monday

        $this->assertSame(1, Target::daysInStage($document->fresh()));
        $this->assertFalse(
            Target::isOverdue($document->fresh()),
            'One working day into a two-day target is not overdue.',
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function a_settled_document_is_never_overdue(): void
    {
        $document = $this->createDocument('user@example.test');
        $document->update([
            'status' => 'approved',
            'submitted_at' => Carbon::parse('2020-01-01 09:00'),
        ]);

        $this->assertFalse(Target::isOverdue($document->fresh()));
    }

    /* ---- The published table is exposed ---- */

    #[Test]
    public function the_published_lead_times_are_readable_by_any_authenticated_user(): void
    {
        $response = $this->asUser()->getJson('/api/lead-times')
            ->assertOk()
            ->assertJsonPath('unit', 'working_days');

        $keys = collect($response->json('activities'))->pluck('key')->all();

        // Every activity in the published table.
        $this->assertEqualsCanonicalizing([
            'upload_encoding',
            'ai_classification',
            'completeness_check',
            'review_simple',
            'review_complex',
            'search_retrieval',
            'manual_retrieval',
            'report_generation',
            'compliance_report',
        ], $keys);
    }

    #[Test]
    public function the_published_lead_times_carry_their_stated_ranges(): void
    {
        $activities = collect($this->asUser()->getJson('/api/lead-times')->json('activities'))
            ->keyBy('key');

        // Same day / immediate.
        foreach (['upload_encoding', 'ai_classification', 'search_retrieval'] as $key) {
            $this->assertSame(0, $activities[$key]['max'], "{$key} should be same-day");
        }

        // Ranges, exactly as published.
        $this->assertSame([1, 2], [$activities['review_simple']['min'], $activities['review_simple']['max']]);
        $this->assertSame([3, 5], [$activities['review_complex']['min'], $activities['review_complex']['max']]);
        $this->assertSame([2, 5], [$activities['compliance_report']['min'], $activities['compliance_report']['max']]);
        $this->assertSame(1, $activities['completeness_check']['max']);
        $this->assertSame(1, $activities['manual_retrieval']['max']);
        $this->assertSame(1, $activities['report_generation']['max']);
    }

    #[Test]
    public function the_published_lead_times_say_what_they_depend_on(): void
    {
        // The table is explicit that these are conditional, not promises.
        $dependsOn = $this->asUser()->getJson('/api/lead-times')->json('depends_on');

        $this->assertNotEmpty($dependsOn);
        $this->assertTrue(
            collect($dependsOn)->contains(fn ($d) => str_contains(strtolower($d), 'completeness')),
        );
    }

    #[Test]
    public function the_review_targets_match_the_published_ranges(): void
    {
        // The badge/aging logic must use the max of the published range —
        // nothing is late until it exceeds the upper bound.
        $this->assertSame(
            config('lead_times.activities.review_simple.max'),
            config('lead_times.review_days.simple'),
        );
        $this->assertSame(
            config('lead_times.activities.review_complex.max'),
            config('lead_times.review_days.complex'),
        );
    }
}
