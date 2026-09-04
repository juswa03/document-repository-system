<?php

namespace App\Http\Controllers\Api;

use App\AI\Contracts\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Document;
use App\Models\Office;
use Illuminate\Http\Request;

class DocumentRepositoryController extends Controller
{
    /**
     * GET /api/repository/documents
     * Searchable, filterable view over the whole document repository
     * (objective 1.3). OSM admins and system admins only — this is
     * cross-office, unlike the user dashboard's "my submissions".
     * `q` now searches document content, not just the title (FR-10).
     */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'objective_id' => ['nullable', 'exists:strategic_objectives,id'],
            'status' => ['nullable', 'in:pending,approved,rejected,revision'],
            'retention_status' => ['nullable', 'in:active,superseded,archived,disposed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            // Include superseded / archived / disposed documents in the results.
            'include_superseded' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->run($request, $filters));
    }

    /**
     * POST /api/repository/search
     * A natural-language query — "approved board minutes about the 2027
     * budget from Head Office" — parsed by the AI layer into the same
     * filters as index(). With the AI layer off, the query is treated as
     * a plain content search (`ai: false`).
     */
    public function search(Request $request, AiProvider $ai)
    {
        $query = $request->validate(['query' => ['required', 'string', 'max:300']])['query'];

        $categories = Category::orderBy('category_name')->pluck('category_name');
        $offices = Office::orderBy('office_name')->pluck('office_name');

        $raw = $ai->interpretSearch($query, $categories->all(), $offices->all());
        $usedAi = $raw !== null;

        $filters = ['q' => $raw['q'] ?? $query];

        if ($usedAi) {
            if (! empty($raw['category']) && $categories->contains($raw['category'])) {
                $filters['category_id'] = Category::where('category_name', $raw['category'])->value('id');
            }
            if (! empty($raw['office']) && $offices->contains($raw['office'])) {
                $filters['office_id'] = Office::where('office_name', $raw['office'])->value('id');
            }
            if (in_array($raw['status'] ?? null, ['pending', 'approved', 'rejected', 'revision'], true)) {
                $filters['status'] = $raw['status'];
            }
            foreach (['date_from', 'date_to'] as $d) {
                if (! empty($raw[$d]) && strtotime($raw[$d]) !== false) {
                    $filters[$d] = date('Y-m-d', strtotime($raw[$d]));
                }
            }
        }

        return response()->json([
            'ai' => $usedAi,
            'interpreted' => $filters,
            'results' => $this->run($request, $filters),
        ]);
    }

    /** Shared query: access scope + filters + relevance ordering when searching. */
    private function run(Request $request, array $filters)
    {
        $documents = Document::with(['category', 'office', 'uploader', 'objectives'])
            ->accessibleBy($request->user())
            ->filter($filters)
            ->when(
                $filters['q'] ?? null,
                fn ($q, $term) => $q->orderByRaw(
                    'MATCH (title, description, keywords, extracted_text) AGAINST (?) DESC',
                    [$term],
                ),
            )
            ->orderByDesc('submitted_at')
            ->paginate(15)
            ->withQueryString();

        return $documents->through(fn (Document $d) => [
            'id' => $d->id,
            'ref' => $d->tracking_no,
            'title' => $d->title,
            'category' => $d->category?->category_name,
            'office' => $d->office?->office_name,
            'uploader' => $d->uploader?->full_name,
            'status' => $d->status,
            'retention_status' => $d->retention_status,
            'objectives' => $d->objectives->pluck('code'),
            'version_number' => $d->version_number,
            'submitted_at' => $d->submitted_at,
        ]);
    }
}
