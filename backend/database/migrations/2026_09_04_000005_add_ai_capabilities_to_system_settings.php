<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            // null = every capability enabled (config/ai.php `capabilities`).
            $table->json('ai_capabilities')->nullable()->after('ai_confidence_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('ai_capabilities');
        });
    }
};
