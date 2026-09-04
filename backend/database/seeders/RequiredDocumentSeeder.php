<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\RequiredDocument;
use Illuminate\Database\Seeder;

/**
 * PLACEHOLDER compliance checklist (Phase 6.2). These make RPT-06 /
 * RPT-07 render and be demonstrable now; OSM replaces them with the real
 * per-office schedule through the Manage Required Documents screen.
 * Nothing in the reports hard-codes a row.
 */
class RequiredDocumentSeeder extends Seeder
{
    public function run(): void
    {
        $cat = fn (string $code) => Category::where('category_code', $code)->value('id');
        $period = 'AY '.(now()->year - 1).'-'.now()->year;

        $rows = [
            ['name' => 'Annual Strategic Plan', 'category_id' => $cat('STRAT'), 'document_type' => 'plan', 'cadence' => 'annual'],
            ['name' => 'Quarterly Performance Report', 'category_id' => $cat('PERF'), 'document_type' => 'report', 'cadence' => 'quarterly'],
            ['name' => 'Accreditation Self-Survey', 'category_id' => $cat('ACCR'), 'document_type' => 'report', 'cadence' => 'annual'],
            ['name' => 'Governance Board Minutes', 'category_id' => $cat('GOV'), 'document_type' => 'minutes', 'cadence' => 'quarterly'],
            ['name' => 'Regulatory Compliance Evidence', 'category_id' => $cat('COMP'), 'document_type' => 'evidence', 'cadence' => 'annual'],
        ];

        foreach ($rows as $row) {
            RequiredDocument::updateOrCreate(
                ['name' => $row['name']],
                [
                    ...$row,
                    'office_id' => null,
                    'reporting_period_label' => $period,
                    'due_offset_days' => 30,
                    'is_active' => true,
                    'notes' => 'Placeholder — replace with OSM\'s approved schedule.',
                ],
            );
        }
    }
}
