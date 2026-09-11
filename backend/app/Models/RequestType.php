<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequestType extends Model
{
    protected $fillable = [
        'type_name',
        'type_code',
        'examples',
        'lead_min_days',
        'lead_max_days',
        'requires_justification',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_justification' => 'boolean',
            'lead_min_days' => 'integer',
            'lead_max_days' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Quickest turnaround first, so the dropdown reads as an escalating
     * list rather than in creation order. Falls back to name for types an
     * admin added without setting an order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('type_name');
    }

    /**
     * The published turnaround as a phrase — "7-10 working days", or
     * "1 working day" when both ends agree. Null when the type carries no
     * published lead time, in which case the interface shows nothing
     * rather than inventing a figure.
     */
    public function leadTimeLabel(): ?string
    {
        if ($this->lead_min_days === null || $this->lead_max_days === null) {
            return null;
        }

        $unit = $this->lead_max_days === 1 ? 'working day' : 'working days';

        return $this->lead_min_days === $this->lead_max_days
            ? "{$this->lead_max_days} {$unit}"
            : "{$this->lead_min_days}-{$this->lead_max_days} {$unit}";
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SubmissionRequest::class);
    }
}
