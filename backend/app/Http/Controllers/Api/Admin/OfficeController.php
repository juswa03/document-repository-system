<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OfficeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'office_name' => ['required', 'string', 'max:255'],
            'office_code' => ['required', 'string', 'max:20', 'unique:offices,office_code'],
        ]);

        $office = Office::create($data);

        AuditLog::record(
            $request->user()->id,
            'office_created',
            "Created office {$office->office_name} ({$office->office_code}).",
            Office::class,
            $office->id
        );

        return response()->json($office, 201);
    }

    public function update(Request $request, Office $office)
    {
        $data = $request->validate([
            'office_name' => ['sometimes', 'string', 'max:255'],
            'office_code' => ['sometimes', 'string', 'max:20', Rule::unique('offices', 'office_code')->ignore($office->id)],
        ]);

        $office->update($data);

        AuditLog::record(
            $request->user()->id,
            'office_edited',
            "Edited office {$office->office_name} ({$office->office_code}).",
            Office::class,
            $office->id
        );

        return response()->json($office);
    }
}
