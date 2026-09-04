<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 — the admin-configurable "required documents" checklist that
 * drives RPT-06 (Compliance Evidence) and RPT-07 (Office Submission
 * Compliance). OSM has not supplied its real schedule; a system admin
 * maintains these rows and the two reports compute expected-vs-actual
 * against them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('required_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Null office_id = every office must submit it.
            $table->foreignId('office_id')->nullable()->constrained('offices')->cascadeOnDelete();
            // Narrow the match by category and/or document type (both optional).
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('document_type')->nullable();
            // Free-text period label the evidence must cover, e.g. "AY 2025-2026".
            $table->string('reporting_period_label')->nullable();
            $table->string('cadence')->default('annual'); // annual|semestral|quarterly|monthly|once
            $table->unsignedSmallInteger('due_offset_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('required_documents');
    }
};
