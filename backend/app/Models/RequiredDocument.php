<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the compliance checklist (Phase 6.2): "office X must submit
 * document Y for period Z". Drives RPT-06 / RPT-07. Maintained by a
 * system admin; placeholder rows are seeded so the reports render before
 * OSM supplies the real schedule.
 */
class RequiredDocument extends Model
{
    public const CADENCES = ['annual', 'semestral', 'quarterly', 'monthly', 'once'];

    protected $fillable = [
        'name',
        'office_id',
        'category_id',
        'document_type',
        'reporting_period_label',
        'cadence',
        'due_offset_days',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'due_offset_days' => 'integer',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Approved documents that satisfy this requirement for a given
     * office: matching category / document type, and — when a period
     * label is set — whose reporting_period contains that label.
     */
    public function matchingDocuments(int $officeId): Builder
    {
        return Document::query()
            ->where('office_id', $officeId)
            ->where('status', 'approved')
            ->when($this->category_id, fn (Builder $q) => $q->where('category_id', $this->category_id))
            ->when($this->document_type, fn (Builder $q) => $q->where('document_type', $this->document_type))
            ->when($this->reporting_period_label, fn (Builder $q) => $q->where('reporting_period', 'like', '%'.$this->reporting_period_label.'%'));
    }

    /** The offices this requirement applies to (all, or just the one set). */
    public function applicableOfficeIds(): \Illuminate\Support\Collection
    {
        return $this->office_id
            ? collect([$this->office_id])
            : Office::query()->pluck('id');
    }
}
