<?php

namespace App\Models;

use App\AI\Suggestion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAiSuggestion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'report_key', 'kind', 'data', 'confidence', 'rationale', 'model',
        'input_tokens', 'output_tokens', 'cost_usd', 'status',
        'resolved_by', 'resolved_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'confidence' => 'float',
            'cost_usd' => 'float',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function fromSuggestion(Document $document, Suggestion $s): self
    {
        return new self([
            'document_id' => $document->id,
            'kind' => $s->kind,
            'data' => $s->data,
            'confidence' => $s->confidence,
            'rationale' => $s->rationale,
            'model' => $s->model,
            'input_tokens' => $s->inputTokens,
            'output_tokens' => $s->outputTokens,
            'cost_usd' => $s->estimatedUsd(),
            'status' => 'pending',
            'created_at' => now(),
        ]);
    }

    /**
     * A report narrative isn't about any one document — it's delivered
     * immediately (there is nothing to accept/dismiss: no record
     * changes, so BR-03's human-confirm gate doesn't apply), it just
     * rides this table for its AI-spend accounting.
     */
    public static function fromReportNarrative(string $reportKey, Suggestion $s): self
    {
        return new self([
            'document_id' => null,
            'report_key' => $reportKey,
            'kind' => $s->kind,
            'data' => $s->data,
            'confidence' => $s->confidence,
            'rationale' => $s->rationale,
            'model' => $s->model,
            'input_tokens' => $s->inputTokens,
            'output_tokens' => $s->outputTokens,
            'cost_usd' => $s->estimatedUsd(),
            'status' => 'accepted',
            'resolved_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Indicative AI spend so far this calendar month, in USD. */
    public static function spendThisMonth(): float
    {
        return (float) static::query()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('cost_usd');
    }
}
