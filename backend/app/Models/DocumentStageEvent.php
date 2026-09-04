<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stage transition in a document's lifecycle (Phase 7.1). Used to
 * measure turnaround against the advisory lead-time targets.
 */
class DocumentStageEvent extends Model
{
    public const STAGE_UPLOADED = 'uploaded';
    public const STAGE_RESUBMITTED = 'resubmitted';
    public const STAGE_AI_ANALYSED = 'ai_analysed';
    public const STAGE_COMPLETENESS_CHECKED = 'completeness_checked';
    public const STAGE_DECIDED = 'decided';

    protected $fillable = ['document_id', 'stage', 'detail', 'actor_id', 'entered_at'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function record(Document $document, string $stage, ?int $actorId = null, ?string $detail = null): void
    {
        static::create([
            'document_id' => $document->id,
            'stage' => $stage,
            'detail' => $detail,
            'actor_id' => $actorId,
            'entered_at' => now(),
        ]);
    }
}
