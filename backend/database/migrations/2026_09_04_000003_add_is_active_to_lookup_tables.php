<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — lookups (categories, offices, request types) can be
 * deactivated instead of only created / edited. A deactivated lookup is
 * hidden from submission dropdowns but kept so existing documents that
 * reference it stay intact; it can be reactivated.
 */
return new class extends Migration
{
    private const TABLES = ['categories', 'offices', 'request_types'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_active')->default(true)->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_active'));
        }
    }
};
