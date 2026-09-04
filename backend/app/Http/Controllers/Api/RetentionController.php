<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\Request;

/**
 * Records-retention lifecycle (DR-14, decision 0.4). Archival takes an
 * approved document out of the working repository but keeps it fully
 * retrievable; disposal is terminal — the file is deleted and only a
 * tombstone record remains. Disposal is always a deliberate, audited
 * human action, never automatic.
 *
 * Gated to osm_admin here. Decision 0.2 was ratified as Option B (three
 * roles, no reviewer/approver split), so `dispose` stays on osm_admin —
 * it maps to the `disposal.approve` capability in
 * App\Authorization\RoleMatrix. If a distinct osm_approver role is added
 * later, move `dispose` behind that capability's new column.
 */
class RetentionController extends Controller
{
    public function overview(Request $request): mixed
    {
        $counts = Document::query()
            ->selectRaw('retention_status, count(*) as c')
            ->groupBy('retention_status')
            ->pluck('c', 'retention_status');

        $dueForArchival = Document::with('category')->retainable()->get()
            ->filter->isRetentionDue()
            ->take(50)
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'category' => $d->category?->category_name,
                'document_date' => $d->document_date?->toDateString(),
                'retention_due_at' => $d->retentionDueAt()?->toDateString(),
            ])->values();

        $dueForDisposal = Document::with('category')->archived()->get()
            ->filter->isDisposalDue()
            ->take(50)
            ->map(fn (Document $d) => [
                'id' => $d->id,
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'category' => $d->category?->category_name,
                'archived_at' => $d->archived_at?->toDateString(),
                'disposal_due_at' => $d->disposalDueAt()?->toDateString(),
            ])->values();

        return response()->json([
            'counts' => [
                'active' => (int) ($counts['active'] ?? 0),
                'superseded' => (int) ($counts['superseded'] ?? 0),
                'archived' => (int) ($counts['archived'] ?? 0),
                'disposed' => (int) ($counts['disposed'] ?? 0),
            ],
            'due_for_archival' => $dueForArchival,
            'due_for_disposal' => $dueForDisposal,
        ]);
    }

    public function archive(Request $request, Document $document): mixed
    {
        if ($document->status !== 'approved') {
            return response()->json(['message' => 'Only approved documents can be archived.'], 422);
        }
        if ($document->retention_status !== 'active') {
            return response()->json(['message' => "This document is already {$document->retention_status}."], 422);
        }

        $document->archive();

        AuditLog::record(
            $request->user()->id,
            'document_archived',
            "Archived {$document->tracking_no} ({$document->title}).",
            Document::class,
            $document->id,
            ['retention_due_at' => $document->retentionDueAt()?->toDateString()],
        );

        return response()->json($this->format($document->fresh()));
    }

    public function restore(Request $request, Document $document): mixed
    {
        if ($document->retention_status !== 'archived') {
            return response()->json(['message' => 'Only an archived document can be restored.'], 422);
        }

        $document->restoreFromArchive();

        AuditLog::record(
            $request->user()->id,
            'document_restored',
            "Restored {$document->tracking_no} from the archive.",
            Document::class,
            $document->id,
        );

        return response()->json($this->format($document->fresh()));
    }

    public function dispose(Request $request, Document $document): mixed
    {
        if ($document->retention_status !== 'archived') {
            return response()->json(['message' => 'Only an archived document can be disposed of.'], 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'A disposal reason is required for the record.',
        ]);

        $disposedFile = $document->file_path;
        $document->dispose($data['reason']);

        AuditLog::record(
            $request->user()->id,
            'document_disposed',
            "Disposed of {$document->tracking_no} ({$document->title}). Reason: {$data['reason']}",
            Document::class,
            $document->id,
            ['disposed_file' => $disposedFile, 'reason' => $data['reason']],
        );

        return response()->json($this->format($document->fresh()));
    }

    private function format(Document $d): array
    {
        return [
            'id' => $d->id,
            'ref' => $d->tracking_no,
            'title' => $d->title,
            'status' => $d->status,
            'retention_status' => $d->retention_status,
            'archived_at' => $d->archived_at,
            'disposed_at' => $d->disposed_at,
            'disposal_reason' => $d->disposal_reason,
        ];
    }
}
