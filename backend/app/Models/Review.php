<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'document_id',
        'request_id',
        'reviewed_by',
        'decision',
        'remarks',
        'checklist',
        'response_file_path',
        'response_file_name',
        'response_document_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'checklist' => 'array',
        ];
    }

    /**
     * A repository document handed over as the answer, as an alternative
     * to uploading a fresh file. Distinct from document(), which is the
     * document being REVIEWED.
     */
    public function responseDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'response_document_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SubmissionRequest::class, 'request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
