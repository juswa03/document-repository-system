<?php

namespace App\Jobs;

use App\AI\Contracts\AiProvider;
use App\AI\DocumentContext;
use App\AI\Suggestion;
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
 * Runs the AI agent layer over a freshly submitted (or resubmitted)
 * document. Every finding is stored as a pending suggestion — nothing is
 * applied to the document (BR-03). No-ops silently when the layer is off
 * or the monthly spend cap is reached.
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

        $this->document->loadMissing('category');
        $context = DocumentContext::fromDocument($this->document);
        $categories = Category::orderBy('category_name')->pluck('category_name')->all();

        // Replace any still-pending suggestions from an earlier submission
        // of the same document.
        DocumentAiSuggestion::where('document_id', $this->document->id)
            ->where('status', 'pending')
            ->delete();

        $suggestions = array_filter([
            $provider->classify($context, $categories),
            $provider->assessCompleteness($context),
            $provider->extractMetadata($context),
            $provider->checkConfidentiality($context, Document::ACCESS_LEVELS),
        ]);

        DocumentStageEvent::record($this->document, DocumentStageEvent::STAGE_AI_ANALYSED);

        foreach ($suggestions as $suggestion) {
            /** @var Suggestion $suggestion */
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
    }
}
