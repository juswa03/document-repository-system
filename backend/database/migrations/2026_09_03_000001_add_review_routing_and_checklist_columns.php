<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['documents', 'requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('assigned_to')->nullable()->after('status')
                    ->constrained('users')->nullOnDelete();
                $t->dateTime('assigned_at')->nullable()->after('assigned_to');
            });
        }

        Schema::table('reviews', function (Blueprint $t) {
            $t->json('checklist')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        foreach (['documents', 'requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('assigned_to');
                $t->dropColumn('assigned_at');
            });
        }

        Schema::table('reviews', function (Blueprint $t) {
            $t->dropColumn('checklist');
        });
    }
};
