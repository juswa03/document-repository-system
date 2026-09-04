<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'version_number',
        'title', 'document_type', 'document_date', 'reporting_period', 'access_level',
        'keywords', 'description', 'remarks', 'category_id',
        'file_path', 'file_format', 'file_size',
        'status', 'review_remarks',
        'superseded_by', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'file_size' => 'integer',
            'version_number' => 'integer',
            'superseded_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
