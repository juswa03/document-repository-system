<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'maintenance_mode',
        'maintenance_message',
        'audit_logging_enabled',
        'ai_enabled',
        'ai_provider',
        'ai_model',
        'ai_monthly_cap_usd',
        'ai_confidence_threshold',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_mode' => 'boolean',
            'audit_logging_enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'ai_monthly_cap_usd' => 'float',
            'ai_confidence_threshold' => 'float',
        ];
    }

    /**
     * Singleton accessor — there is exactly one settings row.
     *
     * Do not key this on a hard-coded id: `id` is not mass-assignable, so
     * `firstOrCreate(['id' => 1], ...)` could never re-find its row once
     * the original was gone, spawning a fresh auto-increment row (and
     * stale reads) on every call.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOr(fn () => static::forceCreate([
            'maintenance_mode' => false,
            'maintenance_message' => null,
            'audit_logging_enabled' => true,
            'ai_enabled' => (bool) config('ai.enabled'),
            'ai_provider' => config('ai.provider'),
            'ai_model' => config('ai.model'),
            'ai_monthly_cap_usd' => config('ai.monthly_cap_usd'),
            'ai_confidence_threshold' => config('ai.confidence_threshold'),
        ]));
    }
}
