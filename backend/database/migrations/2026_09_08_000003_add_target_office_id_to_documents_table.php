<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('target_office_id')
                ->nullable()
                ->after('office_id')
                ->constrained('offices')
                ->nullOnDelete();
        });

        // Back-fill: existing documents route to the same office they came from
        DB::statement('UPDATE documents SET target_office_id = office_id WHERE target_office_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_office_id');
        });
    }
};
