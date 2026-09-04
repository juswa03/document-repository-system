<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.2 — governance review cadence (BR-07). A log of the periodic
 * OSM reviews of categories, access levels and retention status, with
 * the next due date so a reminder can fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->string('scope'); // categories | access_levels | retention
            $table->dateTime('performed_at');
            $table->text('notes')->nullable();
            $table->date('next_due_at');
            $table->timestamps();

            $table->index(['scope', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_reviews');
    }
};
