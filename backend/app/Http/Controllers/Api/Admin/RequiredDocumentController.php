<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RequiredDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for the compliance checklist (Phase 6.2, RPT-06 / RPT-07).
 * system_admin only — it is reference/config data, like offices and
 * categories.
 */
class RequiredDocumentController extends Controller
{
    public function index()
    {
        return response()->json(
            RequiredDocument::with(['office:id,office_name', 'category:id,category_name'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $row = RequiredDocument::create($data);
        $this->audit($request, 'required_document_created', $row);

        return response()->json($row->load(['office:id,office_name', 'category:id,category_name']), 201);
    }

    public function update(Request $request, RequiredDocument $requiredDocument)
    {
        $data = $this->validated($request);

        $requiredDocument->update($data);
        $this->audit($request, 'required_document_updated', $requiredDocument);

        return response()->json($requiredDocument->load(['office:id,office_name', 'category:id,category_name']));
    }

    public function destroy(Request $request, RequiredDocument $requiredDocument)
    {
        $this->audit($request, 'required_document_deleted', $requiredDocument);
        $requiredDocument->delete();

        return response()->json(['message' => 'Removed.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'reporting_period_label' => ['nullable', 'string', 'max:120'],
            'cadence' => ['required', Rule::in(RequiredDocument::CADENCES)],
            'due_offset_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function audit(Request $request, string $action, RequiredDocument $row): void
    {
        AuditLog::record(
            $request->user()->id,
            $action,
            "{$action}: {$row->name}",
            RequiredDocument::class,
            $row->id,
        );
    }
}
