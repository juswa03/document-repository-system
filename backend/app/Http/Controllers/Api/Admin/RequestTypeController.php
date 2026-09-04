<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RequestType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RequestTypeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'type_name' => ['required', 'string', 'max:255'],
            'type_code' => ['required', 'string', 'max:20', 'unique:request_types,type_code'],
        ]);

        $type = RequestType::create($data);

        AuditLog::record(
            $request->user()->id,
            'request_type_created',
            "Created request type {$type->type_name} ({$type->type_code}).",
            RequestType::class,
            $type->id
        );

        return response()->json($type, 201);
    }

    public function update(Request $request, RequestType $requestType)
    {
        $data = $request->validate([
            'type_name' => ['sometimes', 'string', 'max:255'],
            'type_code' => ['sometimes', 'string', 'max:20', Rule::unique('request_types', 'type_code')->ignore($requestType->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        $wasActive = $requestType->is_active;
        $requestType->update($data);

        $verb = array_key_exists('is_active', $data) && $data['is_active'] !== $wasActive
            ? ($data['is_active'] ? 'Reactivated' : 'Deactivated')
            : 'Edited';

        AuditLog::record(
            $request->user()->id,
            $verb === 'Edited' ? 'request_type_edited' : 'request_type_'.strtolower($verb),
            "{$verb} request type {$requestType->type_name} ({$requestType->type_code}).",
            RequestType::class,
            $requestType->id
        );

        return response()->json($requestType);
    }
}
