<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('target_office_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('offices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_office_id');
        });
    }
};
