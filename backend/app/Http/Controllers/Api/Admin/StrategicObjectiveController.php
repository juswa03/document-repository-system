<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\StrategicObjective;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * System-admin management of the strategic-objective tree (Phase 11 /
 * DR objective linkage). The tree is placeholder data until the parent
 * objectives document lands (decision 0.8); this screen lets the OSM
 * enter the real one without a code change.
 */
class StrategicObjectiveController extends Controller
{
    public function index()
    {
        $flat = StrategicObjective::withCount('documents')->orderBy('code')->get();

        return response()->json([
            'summary' => [
                'goals' => $flat->whereNull('parent_id')->count(),
                'sub_objectives' => $flat->whereNotNull('parent_id')->count(),
                'active' => $flat->where('is_active', true)->count(),
                'inactive' => $flat->where('is_active', false)->count(),
                'linked_documents' => Document::whereHas('objectives')->count(),
            ],
            'tree' => StrategicObjective::tree()->map(fn ($g) => $this->node($g)),
            'flat' => $flat->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'parent_id' => $o->parent_id,
                'is_active' => $o->is_active,
                'document_count' => $o->documents_count,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $objective = StrategicObjective::create($data);
        $this->audit($request, 'strategic_objective_created', $objective);

        return response()->json($objective, 201);
    }

    public function update(Request $request, StrategicObjective $strategicObjective)
    {
        $data = $this->validated($request, $strategicObjective);

        if (($data['parent_id'] ?? null) === $strategicObjective->id) {
            return response()->json(['message' => 'An objective cannot be its own parent.'], 422);
        }

        $strategicObjective->update($data);
        $this->audit($request, 'strategic_objective_edited', $strategicObjective);

        return response()->json($strategicObjective);
    }

    public function destroy(Request $request, StrategicObjective $strategicObjective)
    {
        $this->audit($request, 'strategic_objective_deleted', $strategicObjective);
        $strategicObjective->delete();   // children detach (nullOnDelete); links cascade

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request, ?StrategicObjective $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:40', Rule::unique('strategic_objectives', 'code')->ignore($existing?->id)],
            'title' => [$required, 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:strategic_objectives,id'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function node(StrategicObjective $o): array
    {
        return [
            'id' => $o->id,
            'code' => $o->code,
            'title' => $o->title,
            'is_active' => $o->is_active,
            'document_count' => $o->documents_count ?? 0,
            'children' => $o->children->map(fn ($c) => $this->node($c)),
        ];
    }

    private function audit(Request $request, string $action, StrategicObjective $o): void
    {
        AuditLog::record(
            $request->user()->id,
            $action,
            ucfirst(str_replace(['strategic_objective_', '_'], ['Strategic objective ', ' '], $action))
                ." {$o->code} — {$o->title}.",
            StrategicObjective::class,
            $o->id,
        );
    }
}
