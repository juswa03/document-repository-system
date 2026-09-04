<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORIES = [
        ['category_name' => 'Strategic Planning',                    'category_code' => 'STRAT'],
        ['category_name' => 'Performance Monitoring',                'category_code' => 'PERF'],
        ['category_name' => 'Accreditation & Quality Assurance',     'category_code' => 'ACCR'],
        ['category_name' => 'Rankings & Internationalization',       'category_code' => 'RANK'],
        ['category_name' => 'Governance',                            'category_code' => 'GOV'],
        ['category_name' => 'Infrastructure & Development Planning', 'category_code' => 'INFRA'],
        ['category_name' => 'Compliance & Regulatory',              'category_code' => 'COMP'],
        ['category_name' => 'Templates & Controlled Forms',          'category_code' => 'TMPL'],
        ['category_name' => 'Administrative',                        'category_code' => 'ADMIN'],
    ];

    /** old generic code => new OSM code */
    private const REMAP = [
        'ACAD' => 'ADMIN',
        'FIN' => 'ADMIN',
        'HR' => 'ADMIN',
        'LEGAL' => 'COMP',
        'PROC' => 'ADMIN',
        'RSCH' => 'ADMIN',
        'STUD' => 'ADMIN',
        'ITSY' => 'INFRA',
        'GENC' => 'ADMIN',
        // 'ADMIN' (Administrative Memoranda) keeps its code — becomes "Administrative".
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::CATEGORIES as $c) {
            DB::table('categories')->updateOrInsert(
                ['category_code' => $c['category_code']],
                ['category_name' => $c['category_name'], 'updated_at' => $now, 'created_at' => $now],
            );
        }

        $idByCode = DB::table('categories')->pluck('id', 'category_code');

        foreach (self::REMAP as $oldCode => $newCode) {
            $oldId = $idByCode[$oldCode] ?? null;
            $newId = $idByCode[$newCode] ?? null;
            if ($oldId && $newId) {
                DB::table('documents')->where('category_id', $oldId)->update(['category_id' => $newId]);
            }
        }

        $keep = array_column(self::CATEGORIES, 'category_code');
        $stale = DB::table('categories')->whereNotIn('category_code', $keep)->pluck('id');
        $inUse = DB::table('documents')->whereIn('category_id', $stale)->pluck('category_id')->unique();

        DB::table('categories')
            ->whereIn('id', $stale->diff($inUse))
            ->delete();
    }

    public function down(): void
    {
        // One-way data migration — the pre-existing generic categories
        // are not restored. Re-run the old seeder if you need them back.
    }
};
