<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false)->after('audit_logging_enabled');
            $table->string('ai_provider')->default('anthropic')->after('ai_enabled');
            $table->string('ai_model')->default('claude-haiku-4-5')->after('ai_provider');
            $table->decimal('ai_monthly_cap_usd', 8, 2)->default(20)->after('ai_model');
            $table->decimal('ai_confidence_threshold', 3, 2)->default(0.60)->after('ai_monthly_cap_usd');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ai_enabled', 'ai_provider', 'ai_model',
                'ai_monthly_cap_usd', 'ai_confidence_threshold',
            ]);
        });
    }
};
