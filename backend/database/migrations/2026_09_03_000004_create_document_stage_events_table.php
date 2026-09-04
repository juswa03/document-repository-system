<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.1 — lead-time instrumentation (NFR-01…NFR-09, decision 0.9).
 * One row per stage transition so turnaround can be measured against the
 * advisory targets in config/lead_times.php. Advisory only — nothing
 * blocks on these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            // uploaded | resubmitted | ai_analysed | completeness_checked | decided
            $table->string('stage');
            $table->string('detail')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('entered_at');
            $table->timestamps();

            $table->index(['document_id', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_stage_events');
    }
};
