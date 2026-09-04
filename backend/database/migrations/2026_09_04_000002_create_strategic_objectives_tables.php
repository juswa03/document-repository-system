<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — links a document to the strategic objectives it supports
 * (DR objective linkage). The mechanism only: the real objective tree
 * comes from the parent objectives document (decision 0.8, still
 * outstanding) and is loaded as data, not code. A placeholder tree is
 * seeded so the feature is exercisable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategic_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('strategic_objectives')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_strategic_objective', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('strategic_objective_id')->constrained('strategic_objectives')->cascadeOnDelete();
            $table->primary(['document_id', 'strategic_objective_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_strategic_objective');
        Schema::dropIfExists('strategic_objectives');
    }
};
