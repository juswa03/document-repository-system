<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('title')->nullable()->after('request_type_id');
            $table->text('description')->nullable()->after('title');
            $table->date('needed_by')->nullable()->after('description');
            $table->decimal('amount', 12, 2)->nullable()->after('needed_by');
            $table->string('access_level')->default('internal')->after('amount');
            $table->text('remarks')->nullable()->after('access_level');
        });

        DB::table('requests')->whereNull('title')->update([
            'title' => DB::raw("CONCAT('Request ', tracking_no)"),
            'description' => 'Migrated request — details were not captured under the pre-0.7 workflow.',
            'needed_by' => now()->addWeek()->toDateString(),
        ]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'needed_by', 'amount', 'access_level', 'remarks']);
        });
    }
};
