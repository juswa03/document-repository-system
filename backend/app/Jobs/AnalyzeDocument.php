<?php

namespace App\Jobs;

use App\AI\Contracts\AiProvider;
use App\AI\DocumentContext;
use App\AI\Suggestion;
use App\Dedup\TextSimilarity;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentAiSuggestion;
use App\Models\DocumentStageEvent;
use App\Models\SystemSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Analyses a freshly submitted (or resubmitted) document. Every finding
 * is stored as a pending suggestion — nothing is applied to the document
 * (BR-03). The deterministic near-duplicate check always runs; the
 * provider-backed suggestions no-op when the AI layer is off or the
 * monthly spend cap is reached.
 */
class AnalyzeDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Document $document) {}

    public function handle(AiProvider $provider): void
    {
        $this->document->loadMissing('category');

        // Replace any still-pending suggestions from an earlier run.
        DocumentAiSuggestion::where('document_id', $this->document->id)
            ->where('status', 'pending')
            ->delete();

        // Deterministic near-duplicate over the extracted text — no
        // provider call, no cost, runs regardless of the AI layer.
        if (($nearDuplicate = $this->nearDuplicate()) !== null) {
            $this->persist($nearDuplicate);
        }

        if (! $provider->isConfigured()) {
            return;
        }

        if (DocumentAiSuggestion::spendThisMonth() >= (float) SystemSetting::current()->ai_monthly_cap_usd) {
            AuditLog::record(
                null,
                'ai_spend_cap_reached',
                "Skipped AI analysis of {$this->document->tracking_no} — monthly spend cap reached.",
                Document::class,
                $this->document->id,
            );

            return;
        }

        $context = DocumentContext::fromDocument($this->document);
        $categories = Category::orderBy('category_name')->pluck('category_name')->all();

        $provided = array_filter([
            $provider->classify($context, $categories),
            $provider->assessCompleteness($context),
            $provider->extractMetadata($context),
            $provider->checkConfidentiality($context, Document::ACCESS_LEVELS),
            $provider->summarize($context, $this->document->extracted_text),
        ]);

        DocumentStageEvent::record($this->document, DocumentStageEvent::STAGE_AI_ANALYSED);

        foreach ($provided as $suggestion) {
            $this->persist($suggestion);
        }
    }

    private function persist(Suggestion $suggestion): void
    {
        $row = DocumentAiSuggestion::fromSuggestion($this->document, $suggestion);
        $row->save();

        AuditLog::record(
            null,
            'ai_suggestion_created',
            "AI {$suggestion->kind} suggestion for {$this->document->tracking_no} "
                .'(confidence '.number_format($suggestion->confidence, 2).').',
            DocumentAiSuggestion::class,
            $row->id,
            ['model' => $suggestion->model, 'cost_usd' => $row->cost_usd],
        );
    }

    private function nearDuplicate(): ?Suggestion
    {
        $match = app(TextSimilarity::class)->nearest($this->document);

        if ($match === null) {
            return null;
        }

        return new Suggestion(
            kind: Suggestion::KIND_NEAR_DUPLICATE,
            data: [
                'duplicate_of' => $match['document']->tracking_no,
                'duplicate_of_id' => $match['document']->id,
                'similarity' => $match['score'],
            ],
            confidence: (float) $match['score'],
            rationale: round($match['score'] * 100).'% of the text trigrams overlap with '
                .$match['document']->tracking_no.' ("'.$match['document']->title.'").',
            model: 'text-trigram-jaccard',
        );
    }
}
