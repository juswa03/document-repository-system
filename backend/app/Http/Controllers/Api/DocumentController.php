<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    /**
     * GET /api/documents/{id}/file
     * Authenticated download — not a public /storage URL. Only the
     * uploader or an admin role can fetch the file, regardless of
     * whether they can guess the storage path.
     */
    public function download(Request $request, int $id)
    {
        $document = Document::findOrFail($id);
        $user = $request->user();

        if ($document->retention_status === 'disposed') {
            abort(410, 'This document has been disposed of under the retention schedule.');
        }

        if (! $document->isAccessibleBy($user)) {
            abort(403, "You don't have access to this document.");
        }

        $disk = Storage::disk(Document::DISK);

        if (! $disk->exists($document->file_path)) {
            abort(404, 'File not found on disk.');
        }

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $downloadName = Str::slug($document->title).($extension ? ".{$extension}" : '');

        AuditLog::record(
            $user->id,
            'document_downloaded',
            "Downloaded document {$document->tracking_no} ({$document->title}).",
            Document::class,
            $document->id
        );

        return $disk->download($document->file_path, $downloadName);
    }

    /**
     * GET /api/documents/{id}/versions
     * The document's version history: the current version plus every
     * superseded snapshot (FR-11 / FR-12). Same access rule as download.
     */
    public function versions(Request $request, int $id)
    {
        $document = Document::with(['versions.category', 'category'])->findOrFail($id);
        $user = $request->user();

        if (! $document->isAccessibleBy($user)) {
            abort(403, "You don't have access to this document.");
        }

        $history = $document->versions
            ->map(fn ($v) => [
                'version_number' => $v->version_number,
                'is_current' => false,
                'title' => $v->title,
                'document_type' => $v->document_type,
                'document_date' => $v->document_date?->toDateString(),
                'reporting_period' => $v->reporting_period,
                'access_level' => $v->access_level,
                'category' => $v->category?->category_name,
                'file_format' => $v->file_format,
                'file_size' => $v->file_size,
                'status_when_superseded' => $v->status,
                'review_remarks' => $v->review_remarks,
                'superseded_at' => $v->superseded_at,
            ])
            ->push([
                'version_number' => $document->version_number,
                'is_current' => true,
                'title' => $document->title,
                'document_type' => $document->document_type,
                'document_date' => $document->document_date?->toDateString(),
                'reporting_period' => $document->reporting_period,
                'access_level' => $document->access_level,
                'category' => $document->category?->category_name,
                'file_format' => $document->file_format,
                'file_size' => $document->file_size,
                'status_when_superseded' => null,
                'review_remarks' => null,
                'superseded_at' => null,
            ])
            ->values();

        return response()->json([
            'ref' => $document->tracking_no,
            'current_version' => $document->version_number,
            'retention_status' => $document->retention_status,
            'versions' => $history,
        ]);
    }
}
