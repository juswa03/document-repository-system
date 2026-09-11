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
            'request_type_id' => $this->typeId('SDR'),
            'title' => 'Copy of the approved 2026 operational plan',
            'description' => 'Requesting a copy of the approved 2026 operational plan for reference in our own planning.',
            'needed_by' => now()->addWeeks(3)->toDateString(),
            'access_level' => 'internal',
        ], $overrides), fn ($v) => $v !== null);
    }

    #[Test]
    public function a_request_cannot_be_submitted_without_the_minimum_metadata(): void
    {
        $this->asUser()->postJson('/api/dashboard/requests', [
            'request_type_id' => $this->typeId('SDR'),
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'description', 'needed_by']);
    }

    #[Test]
    public function an_inactive_request_type_can_no_longer_be_submitted(): void
    {
        // The administrative transaction types (leave, supply, travel,
        // budget) were retired by deactivation, so old records still
        // resolve while nobody can file a new one. Any inactive type
        // behaves the same way.
        $retired = RequestType::create([
            'type_name' => 'Budget request',
            'type_code' => 'BUD',
            'is_active' => false,
        ]);

        $this->asUser()->postJson('/api/dashboard/requests', $this->payload([
            'request_type_id' => $retired->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['request_type_id']);
    }

    #[Test]
    public function a_complete_request_is_accepted_and_gets_a_tracking_number(): void
    {
        $res = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('title', 'Copy of the approved 2026 operational plan');

        $this->assertMatchesRegularExpression('/^SDR-\d{8}-\d{3}$/', $res->json('ref'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_submitted']);
    }

    #[Test]
    public function a_request_enters_the_review_queue_and_can_be_assigned(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()->json('id');

        $queue = $this->asOfficeAdmin()->getJson('/api/office-admin/queue')->assertOk()->json('data');
        $this->assertContains('request', collect($queue)->pluck('kind')->all());

        $this->asOfficeAdmin()->postJson("/api/office-admin/requests/{$id}/assign", [
            'assignee_id' => $this->userId('office.admin@example.test'),
        ])->assertOk()->assertJsonPath('assigned_to', $this->userId('office.admin@example.test'));
    }

    #[Test]
    public function approving_a_request_requires_the_request_checklist(): void
    {
        $id = $this->asUser()->postJson('/api/dashboard/requests', $this->payload())
            ->assertCreated()->json('id');

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request', 'id' => $id, 'decision' => 'approved',
        ])->assertStatus(422);

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
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

        $this->asOfficeAdmin()->postJson('/api/office-admin/reviews', [
            'kind' => 'request', 'id' => $id, 'decision' => 'revision', 'remarks' => 'add the exact dates',
        ])->assertCreated();

        $this->asUser()->postJson("/api/dashboard/requests/{$id}/resubmit", [
            'description' => 'Requesting five days of annual leave, 22–26 December, for family reasons.',
        ])->assertOk()->assertJsonPath('status', 'pending');

        $this->assertSame($ref, SubmissionRequest::find($id)->tracking_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'request_resubmitted']);
    }
}
