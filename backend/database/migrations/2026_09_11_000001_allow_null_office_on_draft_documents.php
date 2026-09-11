<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.office_id is nullable, so an account can exist without an office.
 * documents.office_id was not, which meant such an account hit a raw
 * integrity error the moment it started a draft — before any validation
 * could explain the problem.
 *
 * Source office stays REQUIRED to submit (SubmissionController's
 * assertSourceOfficeIsKnown and the draft completeness gate enforce it);
 * this only lets a half-written draft exist while the office is still
 * missing, so the person gets a message instead of a 500.
 *
 * doctrine/dbal isn't installed, so MODIFY COLUMN directly. The foreign
 * key is dropped and re-added because MySQL will not alter a column's
 * nullability while a constraint references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP FOREIGN KEY documents_office_id_foreign');
        DB::statement('ALTER TABLE documents MODIFY COLUMN office_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_office_id_foreign '
            .'FOREIGN KEY (office_id) REFERENCES offices(id)');
    }

    public function down(): void
    {
        // Rows with no office cannot exist in the old shape; they are
        // drafts by definition, so discard them before restoring NOT NULL.
        DB::table('documents')->whereNull('office_id')->delete();

        DB::statement('ALTER TABLE documents DROP FOREIGN KEY documents_office_id_foreign');
        DB::statement('ALTER TABLE documents MODIFY COLUMN office_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_office_id_foreign '
            .'FOREIGN KEY (office_id) REFERENCES offices(id)');
    }
};
