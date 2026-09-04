<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;

class DocumentRepositoryController extends Controller
{
    /**
     * GET /api/repository/documents
     * Searchable, filterable view over the whole document repository
     * (objective 1.3). OSM admins and system admins only — this is
     * cross-office, unlike the user dashboard's "my submissions".
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'status' => ['nullable', 'in:pending,approved,rejected,revision'],
            'retention_status' => ['nullable', 'in:active,superseded,archived,disposed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            // Include superseded / archived / disposed documents in the results.
            'include_superseded' => ['nullable', 'boolean'],
        ]);

        $documents = Document::with(['category', 'office', 'uploader'])
            ->accessibleBy($request->user())
            ->filter($filters)
            ->orderByDesc('submitted_at')
            ->paginate(15)
            ->withQueryString();

        return response()->json(
            $documents->through(fn (Document $d) => [
                'id' => $d->id,
                'ref' => $d->tracking_no,
                'title' => $d->title,
                'category' => $d->category?->category_name,
                'office' => $d->office?->office_name,
                'uploader' => $d->uploader?->full_name,
                'status' => $d->status,
                'retention_status' => $d->retention_status,
                'version_number' => $d->version_number,
                'submitted_at' => $d->submitted_at,
            ])
        );
    }
}
