<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('document_type', 20)->nullable()->after('title');       // DR-02
            $table->date('document_date')->nullable()->after('document_type');      // DR-05
            $table->string('reporting_period', 120)->nullable()->after('document_date'); // DR-06
            $table->string('access_level', 20)->default('internal')->after('reporting_period'); // DR-07
            $table->string('keywords', 500)->nullable()->after('access_level');     // DR-09
            $table->text('description')->nullable()->after('keywords');             // DR-10
            $table->string('retention_status', 20)->default('active')->after('status'); // DR-14
            $table->unsignedInteger('version_number')->default(1)->after('retention_status'); // DR-13
            $table->string('file_format', 20)->nullable()->after('file_path');      // DR-17
            $table->unsignedBigInteger('file_size')->nullable()->after('file_format'); // DR-18
            $table->text('remarks')->nullable()->after('description');              // DR-15 (uploader remarks)
        });

        // Best-effort backfill for rows that predate the metadata requirement.
        DB::table('documents')->whereNull('document_date')->update([
            'document_date' => DB::raw('DATE(submitted_at)'),
        ]);
        DB::table('documents')->whereNull('file_format')->whereNotNull('file_path')->update([
            'file_format' => DB::raw("LOWER(SUBSTRING_INDEX(file_path, '.', -1))"),
        ]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'document_type', 'document_date', 'reporting_period', 'access_level',
                'keywords', 'description', 'retention_status', 'version_number',
                'file_format', 'file_size', 'remarks',
            ]);
        });
    }
};
