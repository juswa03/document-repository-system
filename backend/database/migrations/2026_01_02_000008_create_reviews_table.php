<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            // Exactly one of document_id / request_id is set per review.
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users');
            // approved | rejected | revision
            $table->string('decision');
            $table->string('remarks')->nullable();
            $table->dateTime('reviewed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
