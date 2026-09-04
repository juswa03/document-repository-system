<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A goal or sub-objective from the OSM's strategic plan. The tree here is
 * a PLACEHOLDER until the parent objectives document is supplied
 * (decision 0.8); it is loaded from a seeder / admin CRUD, never
 * hard-coded into behaviour.
 */
class StrategicObjective extends Model
{
    protected $fillable = ['parent_id', 'code', 'title', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('code');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_strategic_objective');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->title}";
    }

    /**
     * The tree, roots first, to any depth. Every node carries its
     * linked-document count (`documents_count`) and a populated
     * `children` relation, built from a single query.
     */
    public static function tree(): Collection
    {
        $all = static::withCount('documents')
            ->orderBy('sort_order')->orderBy('code')
            ->get();

        $childrenOf = $all->groupBy('parent_id');

        $build = function (self $node) use (&$build, $childrenOf) {
            $node->setRelation(
                'children',
                ($childrenOf[$node->id] ?? new Collection)->map($build)->values()
            );

            return $node;
        };

        return $all->whereNull('parent_id')->map($build)->values();
    }
}
