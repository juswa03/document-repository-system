<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request types carry their own published lead time and examples.
 *
 * These live on the row rather than in config so a system admin can
 * retune them through the existing Request Types screen without a
 * deploy — the same way categories and offices are already editable.
 *
 * Nullable throughout: a type added by an admin who does not fill these
 * in still works, it simply shows no guidance panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            // What this type covers, shown to the requester as examples.
            $table->string('examples', 500)->nullable()->after('type_name');

            // Published turnaround, in WORKING days (min-max).
            $table->unsignedSmallInteger('lead_min_days')->nullable()->after('examples');
            $table->unsignedSmallInteger('lead_max_days')->nullable()->after('lead_min_days');

            // "Subject to approval" types demand a stated reason.
            $table->boolean('requires_justification')->default(false)->after('lead_max_days');

            // Orders the dropdown from quickest to slowest instead of by id.
            $table->unsignedSmallInteger('display_order')->default(0)->after('requires_justification');
        });
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn([
                'examples',
                'lead_min_days',
                'lead_max_days',
                'requires_justification',
                'display_order',
            ]);
        });
    }
};
