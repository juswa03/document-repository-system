<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');

            // Snapshot of the document as it stood when this version was superseded.
            $table->string('title');
            $table->string('document_type', 20)->nullable();
            $table->date('document_date')->nullable();
            $table->string('reporting_period', 120)->nullable();
            $table->string('access_level', 20);
            $table->string('keywords', 500)->nullable();
            $table->text('description')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('file_path');
            $table->string('file_format', 20)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('status', 20);          // status this version held when superseded (usually 'revision')
            $table->text('review_remarks')->nullable(); // the reviewer note that triggered the revision

            $table->foreignId('superseded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('superseded_at');
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
