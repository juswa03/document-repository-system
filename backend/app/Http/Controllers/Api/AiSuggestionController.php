<?php

namespace App\Http\Controllers\Api;

use App\AI\Suggestion;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use Illuminate\Http\Request;

/**
 * Reviewer-facing surface for the AI agent layer. Suggestions are
 * produced asynchronously by AnalyzeDocument; nothing here is applied to
 * a document until a reviewer explicitly accepts it (BR-03).
 */
class AiSuggestionController extends Controller
{
    public function index(Document $document): mixed
    {
        $rows = $document->aiSuggestions()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DocumentAiSuggestion $s) => $this->format($s));

        return response()->json($rows);
    }

    public function accept(Request $request, DocumentAiSuggestion $aiSuggestion): mixed
    {
        if ($aiSuggestion->status !== 'pending') {
            return response()->json(['message' => 'This suggestion has already been resolved.'], 422);
        }

        $applied = $this->apply($aiSuggestion);

        $aiSuggestion->update([
            'status' => 'accepted',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        AuditLog::record(
            $request->user()->id,
            'ai_suggestion_accepted',
            "Accepted the AI {$aiSuggestion->kind} suggestion for {$aiSuggestion->document->tracking_no}.",
            DocumentAiSuggestion::class,
            $aiSuggestion->id,
            ['applied' => $applied],
        );

        return response()->json($this->format($aiSuggestion->fresh()));
    }

    public function dismiss(Request $request, DocumentAiSuggestion $aiSuggestion): mixed
    {
        if ($aiSuggestion->status !== 'pending') {
            return response()->json(['message' => 'This suggestion has already been resolved.'], 422);
        }

        $aiSuggestion->update([
            'status' => 'dismissed',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        AuditLog::record(
            $request->user()->id,
            'ai_suggestion_dismissed',
            "Dismissed the AI {$aiSuggestion->kind} suggestion for {$aiSuggestion->document->tracking_no}.",
            DocumentAiSuggestion::class,
            $aiSuggestion->id,
        );

        return response()->json($this->format($aiSuggestion->fresh()));
    }

    /**
     * Apply an accepted suggestion to its document. This is the only
     * place the AI layer causes a document field to change, and only on
     * an explicit reviewer action (BR-03). A completeness note has
     * nothing to apply — accepting it is an acknowledgement.
     *
     * @return array<string, mixed> the fields actually changed
     */
    private function apply(DocumentAiSuggestion $s): array
    {
        $document = $s->document;
        $applied = [];

        if ($s->kind === Suggestion::KIND_CLASSIFICATION) {
            $categoryId = Category::where('category_name', $s->data['category'] ?? null)->value('id');
            if ($categoryId !== null) {
                $applied['category_id'] = $document->category_id = $categoryId;
            }
            if (in_array($s->data['document_type'] ?? null, Document::TYPES, true)) {
                $applied['document_type'] = $document->document_type = $s->data['document_type'];
            }
        }

        if ($s->kind === Suggestion::KIND_METADATA) {
            foreach (['reporting_period', 'keywords', 'description'] as $field) {
                if (is_string($s->data['fields'][$field] ?? null)) {
                    $applied[$field] = $document->{$field} = $s->data['fields'][$field];
                }
            }
            $date = $s->data['fields']['document_date'] ?? null;
            if (is_string($date) && strtotime($date) !== false) {
                $applied['document_date'] = $document->document_date = $date;
            }
        }

        if ($s->kind === Suggestion::KIND_CONFIDENTIALITY
            && in_array($s->data['access_level'] ?? null, Document::ACCESS_LEVELS, true)) {
            $applied['access_level'] = $document->access_level = $s->data['access_level'];
        }

        if ($applied !== []) {
            $document->save();
        }

        return $applied;
    }

    private function format(DocumentAiSuggestion $s): array
    {
        return [
            'id' => $s->id,
            'document_id' => $s->document_id,
            'kind' => $s->kind,
            'data' => $s->data,
            'confidence' => $s->confidence,
            'rationale' => $s->rationale,
            'model' => $s->model,
            'cost_usd' => $s->cost_usd,
            'status' => $s->status,
            'resolved_by' => $s->resolver?->full_name,
            'resolved_at' => $s->resolved_at,
            'created_at' => $s->created_at,
        ];
    }
}
