<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reviewer answering a request can either upload a fresh file
 * (response_file_path) or point at a document already in the repository.
 *
 * Linking rather than copying keeps one authoritative record: the
 * document keeps its own tracking number, version history and retention
 * state, and a later revision of it is not silently orphaned from the
 * approval that handed it over.
 *
 * nullOnDelete: if the linked document is ever removed, the review stays
 * — its decision and remarks are still part of the record — it simply no
 * longer offers a file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('response_document_id')
                ->nullable()
                ->after('response_file_name')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('response_document_id');
        });
    }
};
