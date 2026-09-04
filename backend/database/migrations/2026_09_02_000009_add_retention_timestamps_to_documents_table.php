<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dateTime('archived_at')->nullable()->after('retention_status');
            $table->dateTime('disposed_at')->nullable()->after('archived_at');
            $table->string('disposal_reason')->nullable()->after('disposed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['archived_at', 'disposed_at', 'disposal_reason']);
        });
    }
};
