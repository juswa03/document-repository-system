<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_no')->unique();
            $table->string('title');
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('office_id')->constrained('offices');
            $table->string('file_path');
            // pending | approved | rejected | revision
            $table->string('status')->default('pending');
            $table->dateTime('submitted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
