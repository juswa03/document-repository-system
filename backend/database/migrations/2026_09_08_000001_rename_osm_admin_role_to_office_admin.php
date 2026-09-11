<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: widen the enum to accept both old and new value
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('system_admin','osm_admin','office_admin','user') DEFAULT 'user'");
        // Step 2: move all existing rows to the new value
        DB::statement("UPDATE users SET role = 'office_admin' WHERE role = 'osm_admin'");
        // Step 3: remove the old value now that no rows use it
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('system_admin','office_admin','user') DEFAULT 'user'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('system_admin','osm_admin','office_admin','user') DEFAULT 'user'");
        DB::statement("UPDATE users SET role = 'osm_admin' WHERE role = 'office_admin'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('system_admin','osm_admin','user') DEFAULT 'user'");
    }
};
