<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function show()
    {
        return response()->json(SystemSetting::current());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_mode' => ['sometimes', 'boolean'],
            // Retained for API/UI compatibility only. Audit logging is
            // always on (PF-18 / BR-06) — this flag no longer disables it.
            'audit_logging_enabled' => ['sometimes', 'boolean'],
        ]);

        // Normalise: 'boolean' validation accepts the strings "false"/"0",
        // but the model's boolean cast would store those as true.
        foreach ($data as $key => $value) {
            $data[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $settings = SystemSetting::current();
        $before = $settings->only(array_keys($data));
        $settings->update($data);

        if ($before !== $data) {
            AuditLog::record(
                $request->user()->id,
                'settings_updated',
                'Updated system settings: '.implode(', ', array_keys($data)).'.',
                SystemSetting::class,
                $settings->id,
                ['before' => $before, 'after' => $settings->only(array_keys($data))]
            );
        }

        return response()->json($settings);
    }
}
