<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    private const BOOLEANS = ['maintenance_mode', 'audit_logging_enabled'];

    public function show()
    {
        return response()->json($this->payload());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'maintenance_mode' => ['sometimes', 'boolean'],
            'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Retained for API/UI compatibility only. Audit logging is
            // always on (PF-18 / BR-06) — this flag no longer disables it.
            'audit_logging_enabled' => ['sometimes', 'boolean'],
        ]);

        // 'boolean' validation accepts the strings "false"/"0"; the model's
        // boolean cast would store those as true. Normalise the flags only —
        // never the free-text message.
        foreach (self::BOOLEANS as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $settings = SystemSetting::current();
        $before = $settings->only(array_keys($data));
        $settings->update($data);
        $after = $settings->only(array_keys($data));

        if ($before !== $after) {
            AuditLog::record(
                $request->user()->id,
                'settings_updated',
                'Updated system settings: '.implode(', ', array_keys($data)).'.',
                SystemSetting::class,
                $settings->id,
                ['before' => $before, 'after' => $after]
            );
        }

        return response()->json($this->payload());
    }

    /**
     * The settings row plus the read-only platform configuration an admin
     * would otherwise have to read from config files / .env.
     */
    private function payload(): array
    {
        $s = SystemSetting::current();

        return [
            'maintenance_mode' => (bool) $s->maintenance_mode,
            'maintenance_message' => $s->maintenance_message,
            'audit_logging_enabled' => (bool) $s->audit_logging_enabled,
            'platform' => [
                'max_upload_mb' => round(config('documents.max_upload_kb') / 1024, 1),
                'allowed_file_types' => config('documents.allowed_mimes'),
                'document_types' => config('documents.types'),
                'access_levels' => config('documents.access_levels'),
                'default_access_level' => config('documents.default_access_level'),
                'retention_statuses' => config('documents.retention_statuses'),
                'near_duplicate_threshold' => config('documents.near_duplicate_threshold'),
                'governance_cadence_months' => config('governance.cadence_months'),
                'token_expiration_minutes' => config('sanctum.expiration'),
            ],
        ];
    }
}
