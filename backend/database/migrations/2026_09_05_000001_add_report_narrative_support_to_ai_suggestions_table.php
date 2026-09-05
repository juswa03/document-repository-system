<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report-narrative row (kind = 'report_narrative') isn't tied to any
 * one document — it's an AI-drafted paragraph over a whole report's
 * aggregate numbers — so document_id has to become optional. report_key
 * records which report it was for. The table still doubles as the AI
 * spend ledger (DocumentAiSuggestion::spendThisMonth()), which is the
 * whole reason to reuse it here instead of a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_suggestions', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->change();
            $table->string('report_key')->nullable()->after('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_suggestions', function (Blueprint $table) {
            $table->dropColumn('report_key');
            $table->foreignId('document_id')->nullable(false)->change();
        });
    }
};
