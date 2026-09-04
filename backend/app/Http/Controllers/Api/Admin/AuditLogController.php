<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        $logs = AuditLog::with('actor')
            ->latest('created_at')
            ->paginate(20);

        return response()->json(
            $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'actor' => $log->actor?->full_name ?? 'System',
                'action' => $log->action,
                'description' => $log->description,
                'created_at' => $log->created_at,
            ])
        );
    }
}
