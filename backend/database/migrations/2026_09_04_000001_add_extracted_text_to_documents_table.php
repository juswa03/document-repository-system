<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — the readable text of an uploaded document, pulled out on
 * submission. Backs full-text search (Phase 9) and AI summarisation /
 * near-duplicate comparison (Phase 10). Nullable: legacy rows and any
 * file the extractor can't read stay NULL and are simply skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('extracted_text')->nullable()->after('description');
            $table->dateTime('text_extracted_at')->nullable()->after('extracted_text');
        });

        // FULLTEXT index for Phase 9 content search. MySQL 5.6+/MariaDB
        // 10.0+ InnoDB support this natively.
        Schema::table('documents', function (Blueprint $table) {
            $table->fullText(['title', 'description', 'keywords', 'extracted_text'], 'documents_content_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText('documents_content_fulltext');
            $table->dropColumn(['extracted_text', 'text_extracted_at']);
        });
    }
};
