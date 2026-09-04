<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('grantee_office_id')->nullable()->constrained('offices')->cascadeOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->string('reason', 500);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['document_id', 'grantee_user_id']);
            $table->index(['document_id', 'grantee_office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
