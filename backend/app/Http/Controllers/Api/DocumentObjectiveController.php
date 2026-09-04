<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\StrategicObjective;
use Illuminate\Http\Request;

/**
 * Links a document to the strategic objectives it supports (DR objective
 * linkage, Phase 11). OSM admins set this during or after review.
 */
class DocumentObjectiveController extends Controller
{
    public function index(Document $document)
    {
        return response()->json(
            $document->objectives()->get(['strategic_objectives.id', 'code', 'title']),
        );
    }

    public function sync(Request $request, Document $document)
    {
        $ids = $request->validate([
            'objective_ids' => ['present', 'array'],
            'objective_ids.*' => ['integer', 'exists:strategic_objectives,id'],
        ])['objective_ids'];

        $document->objectives()->sync($ids);
        $codes = StrategicObjective::whereIn('id', $ids)->orderBy('code')->pluck('code');

        AuditLog::record(
            $request->user()->id,
            'document_objectives_set',
            $codes->isEmpty()
                ? "Cleared the strategic-objective links on {$document->tracking_no}."
                : "Linked {$document->tracking_no} to ".$codes->implode(', ').'.',
            Document::class,
            $document->id,
            ['objective_ids' => $ids],
        );

        return response()->json($document->objectives()->get(['strategic_objectives.id', 'code', 'title']));
    }
}
