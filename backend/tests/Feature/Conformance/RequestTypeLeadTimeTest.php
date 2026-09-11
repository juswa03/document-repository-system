<?php

namespace Tests\Feature\Conformance;

use App\Models\RequestType;
use App\Models\SubmissionRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * The published request categories and their lead times.
 *
 * Each type carries its own examples and turnaround on the row rather
 * than in config, so a system admin can retune them through the existing
 * Request Types screen. These tests pin the published table and the two
 * rules derived from it: ordering, and "subject to approval" types
 * demanding a stated reason.
 */
class RequestTypeLeadTimeTest extends ConformanceTestCase
{
    private function typeId(string $code): int
    {
        return RequestType::where('type_code', $code)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'request_type_id' => $this->typeId('SDR'),
            'title' => 'Copy of the approved 2026 operational plan',
            'description' => 'Requesting a copy of the approved 2026 operational plan for reference.',
            'needed_by' => now()->addWeeks(3)->toDateString(),
            'access_level' => 'internal',
        ], $overrides), fn ($v) => $v !== null);
    }

    #[Test]
    public function the_published_request_categories_are_seeded_with_their_lead_times(): void
    {
        $expected = [
            'SDR' => [1, 3],
            'URG' => [1, 2],
            'STD' => [3, 5],
            'CPX' => [7, 10],
            'SEN' => [10, 15],
        ];

        foreach ($expected as $code => [$min, $max]) {
            $type = RequestType::where('type_code', $code)->first();

            $this->assertNotNull($type, "Request type {$code} is missing.");
            $this->assertTrue($type->is_active, "Request type {$code} should be selectable.");
            $this->assertSame($min, $type->lead_min_days, "{$code} min lead time");
            $this->assertSame($max, $type->lead_max_days, "{$code} max lead time");
            $this->assertNotEmpty($type->examples, "{$code} should carry examples for the requester.");
        }
    }

    #[Test]
    public function the_retired_administrative_types_are_not_offered(): void
    {
        // A fresh install never creates them at all; an existing install
        // keeps the rows (requests.request_type_id is a foreign key) but
        // deactivates them. Either way none is selectable.
        $offered = collect($this->asUser()->getJson('/api/request-types')->assertOk()->json())
            ->pluck('type_code');

        foreach (['LVE', 'SUP', 'TRV', 'BUD', 'OTH'] as $code) {
            $this->assertFalse($offered->contains($code), "Retired type {$code} must not be selectable.");
        }
    }

    #[Test]
    public function an_existing_retired_type_is_deactivated_rather_than_deleted(): void
    {
        // Simulate an install that predates the change: the row exists
        // and is active, and something already references it.
        $legacy = RequestType::create([
            'type_name' => 'Budget request',
            'type_code' => 'BUD',
            'is_active' => true,
        ]);

        (new \Database\Seeders\LookupDataSeeder)->run();

        $legacy->refresh();

        // Kept, so old requests still resolve their type...
        $this->assertNotNull(RequestType::find($legacy->id));
        // ...but no longer offered.
        $this->assertFalse($legacy->is_active);
    }

    #[Test]
    public function the_type_list_is_ordered_quickest_first_and_carries_its_guidance(): void
    {
        $rows = $this->asUser()->getJson('/api/request-types')->assertOk()->json();

        $this->assertSame(
            ['SDR', 'URG', 'STD', 'CPX', 'SEN'],
            collect($rows)->pluck('type_code')->all(),
            'The dropdown should escalate from quickest to slowest.',
        );

        $simple = collect($rows)->firstWhere('type_code', 'SDR');
        $this->assertSame('1-3 working days', $simple['lead_time_label']);
        $this->assertNotEmpty($simple['examples']);
        $this->assertFalse($simple['requires_justification']);

        $urgent = collect($rows)->firstWhere('type_code', 'URG');
        $this->assertTrue($urgent['requires_justification']);
    }

    #[Test]
    public function a_single_day_range_reads_in_the_singular(): void
    {
        $type = RequestType::where('type_code', 'SDR')->first();
        $type->update(['lead_min_days' => 1, 'lead_max_days' => 1]);

        $this->assertSame('1 working day', $type->fresh()->leadTimeLabel());
    }

    #[Test]
    public function a_type_with_no_published_lead_time_shows_none(): void
    {
        // An admin may add a type without filling these in; the interface
        // must then show nothing rather than invent a figure.
        $type = RequestType::create([
            'type_name' => 'Ad-hoc request',
            'type_code' => 'ADH',
            'is_active' => true,
        ]);

        $this->assertNull($type->leadTimeLabel());
    }

    /* ---- "Subject to approval" types demand a reason ---- */

    #[Test]
    public function an_urgent_request_is_refused_without_a_stated_reason(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('URG'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['remarks']);

        $this->assertSame(0, SubmissionRequest::count());
    }

    #[Test]
    public function a_token_reason_is_not_enough(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('URG'),
            'remarks' => 'urgent',
        ]))->assertStatus(422)->assertJsonValidationErrors(['remarks']);
    }

    #[Test]
    public function an_urgent_request_with_a_reason_is_accepted(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('URG'),
            'remarks' => 'Needed for the Board meeting on the 18th; instructed by the VP for Planning.',
        ]))->assertCreated();

        $this->assertSame(1, SubmissionRequest::count());
    }

    #[Test]
    public function a_sensitive_request_also_demands_a_reason(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('SEN'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['remarks']);
    }

    #[Test]
    public function an_ordinary_request_needs_no_reason(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('STD'),
        ]))->assertCreated();
    }

    #[Test]
    public function the_tracking_number_uses_the_new_type_code(): void
    {
        $ref = $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('CPX'),
        ]))->assertCreated()->json('ref');

        $this->assertStringStartsWith('CPX-', $ref);
    }
}
