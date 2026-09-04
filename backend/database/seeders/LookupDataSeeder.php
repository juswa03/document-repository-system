<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\RequestType;
use Illuminate\Database\Seeder;

class LookupDataSeeder extends Seeder
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

    private const REQUEST_TYPES = [
        ['type_name' => 'Leave request',          'type_code' => 'LVE'],
        ['type_name' => 'Supply requisition',     'type_code' => 'SUP'],
        ['type_name' => 'Travel authorization',   'type_code' => 'TRV'],
        ['type_name' => 'Budget request',         'type_code' => 'BUD'],
        ['type_name' => 'Other',                  'type_code' => 'OTH'],
    ];

    public function run(): void
    {
        foreach (self::REQUEST_TYPES as $type) {
            RequestType::updateOrCreate(['type_code' => $type['type_code']], $type);
        }

        foreach (self::CATEGORIES as $category) {
            Category::updateOrCreate(['category_code' => $category['category_code']], $category);
        }
    }
}
