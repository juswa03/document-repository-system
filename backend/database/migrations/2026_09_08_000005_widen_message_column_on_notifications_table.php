<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The message column was VARCHAR(255) — too small for a review remark
 * (validated up to 2000 chars) wrapped in the notification sentence.
 * doctrine/dbal isn't installed, so MODIFY COLUMN directly rather than
 * Blueprint::change().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY COLUMN message TEXT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications MODIFY COLUMN message VARCHAR(255) NOT NULL');
    }
};
