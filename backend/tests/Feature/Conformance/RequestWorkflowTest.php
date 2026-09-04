<?php

namespace Tests\Feature\Conformance;

use App\Models\RequestType;
use App\Models\SubmissionRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * Decision 0.7 — the non-document request workflow, kept and formalised
 * to document-grade metadata / audit / routing
 * (docs/request-workflow-spec.md).
 */
class RequestWorkflowTest extends ConformanceTestCase
{
    private function typeId(string $code): int
    {
        return RequestType::where('type_code', $code)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'request_type_id' => $this->typeId('LVE'),
            'title' => 'Annual leave — December',
            'description' => 'Requesting five days of annual leave in the last week of December for family reasons.',
            'needed_by' => now()->addWeeks(3)->toDateString(),
            'access_level' => 'internal',
        ], $overrides), fn ($v) => $v !== null);
    }

    #[Test]
    public function a_request_cannot_be_submitted_without_the_minimum_metadata(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', [
            'request_type_id' => $this->typeId('LVE'),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'description', 'needed_by']);
    }

    #[Test]
    public function budget_and_supply_requests_require_an_amount(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('BUD'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['amount']);

        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $this->typeId('BUD'),
            'amount' => 15000,
        ]))->assertCreated();
    }

    #[Test]
    public function a_complete_request_is_accepted_and_gets_a_tracking_number(): void
    {
        $res = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('title', 'Annual leave — December');

        $this->assertMatchesRegularExpression('/^LVE-\d{8}-\d{3}$/', $res->json('ref'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_submitted']);
    }

    #[Test]
    public function a_request_enters_the_review_queue_and_can_be_assigned(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()->json('id');

        $queue = $this->asOsmAdmin()->getJson('/api/osm-admin/queue')->assertOk()->json();
        $this->assertContains('request', collect($queue)->pluck('kind')->all());

        $this->asOsmAdmin()->postJson("/api/osm-admin/requests/{$id}/assign", [
            'assignee_id' => $this->userId('osm.admin@example.test'),
        ])->assertOk()->assertJsonPath('assigned_to', $this->userId('osm.admin@example.test'));
    }

    #[Test]
    public function approving_a_request_requires_the_request_checklist(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()->json('id');

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'request', 'id' => $id, 'decision' => 'approved',
        ])->assertStatus(422);

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'request', 'id' => $id, 'decision' => 'approved',
            'checklist' => $this->completeChecklist('request'),
        ])->assertCreated();

        $this->assertSame('approved', SubmissionRequest::find($id)->status);
    }

    #[Test]
    public function a_revision_resubmit_keeps_the_same_tracking_number(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()->json('id');
        $ref = SubmissionRequest::find($id)->tracking_no;

        $this->asOsmAdmin()->postJson('/api/osm-admin/reviews', [
            'kind' => 'request', 'id' => $id, 'decision' => 'revision', 'remarks' => 'add the exact dates',
        ])->assertCreated();

        $this->asUser()->postJson("/api/dashboard/requests/{$id}/resubmit", [
            'description' => 'Requesting five days of annual leave, 22–26 December, for family reasons.',
        ])->assertOk()->assertJsonPath('status', 'pending');

        $this->assertSame($ref, SubmissionRequest::find($id)->tracking_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_resubmitted']);
    }
}
