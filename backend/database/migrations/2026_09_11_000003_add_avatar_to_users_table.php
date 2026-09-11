<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile pictures.
 *
 * Only the storage path is kept — the file itself lives on the PRIVATE
 * disk alongside documents, served through an authenticated route rather
 * than a public URL. A public disk would make every staff member's photo
 * readable by anyone who guessed the filename, which is not a trade this
 * system makes anywhere else.
 *
 * Nullable: a profile picture is optional, and the interface falls back
 * to the user's initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('office_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
