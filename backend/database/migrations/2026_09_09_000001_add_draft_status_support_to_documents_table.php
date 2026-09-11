<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Draft — document details are being encoded but not yet submitted."
 *
 * A draft has no tracking number: numbers are issued at submission so a
 * draft never consumes one it might never use, and never leaves a gap in
 * the daily sequence. The unique index stays — MySQL allows repeated
 * NULLs in a UNIQUE column, so many drafts can coexist.
 *
 * submitted_at becomes nullable for the same reason: a draft has not been
 * submitted, and back-dating it to the draft's creation would corrupt
 * every lead-time figure.
 *
 * doctrine/dbal isn't installed, so MODIFY COLUMN directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents MODIFY COLUMN tracking_no VARCHAR(255) NULL');
        DB::statement('ALTER TABLE documents MODIFY COLUMN submitted_at DATETIME NULL');

        // A draft is by definition incomplete — the uploader may not have
        // chosen a category or attached the file yet. Both are required
        // again at submission (SubmissionController::assertDraftIsComplete),
        // so no submitted document can reach the repository without them.
        DB::statement('ALTER TABLE documents MODIFY COLUMN category_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE documents MODIFY COLUMN file_path VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Drafts can't exist in the old shape — they have no tracking
        // number to keep. Discard them, then restore the NOT NULLs.
        DB::table('documents')->where('status', 'draft')->delete();
        DB::statement('ALTER TABLE documents MODIFY COLUMN tracking_no VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE documents MODIFY COLUMN submitted_at DATETIME NOT NULL');
        DB::statement('ALTER TABLE documents MODIFY COLUMN category_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE documents MODIFY COLUMN file_path VARCHAR(255) NOT NULL');
    }
};
