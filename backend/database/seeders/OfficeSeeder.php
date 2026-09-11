<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    private const OFFICES = [
        ['office_code' => 'ORKM',  'office_name' => 'Office of Research and Knowledge Management'],
        ['office_code' => 'QAAO',  'office_name' => 'Quality Assurance and Accreditation Office'],
        ['office_code' => 'ODISM', 'office_name' => 'Office of Digitalization and Information System Management'],
        ['office_code' => 'ODAPM', 'office_name' => 'Office of Data Analytics and Performance Management'],
        ['office_code' => 'BO',    'office_name' => 'Budget Office'],
        ['office_code' => 'HRMO',  'office_name' => 'Human Resources & Management Office'],
        ['office_code' => 'OPDGS', 'office_name' => 'Office of Physical Development, General Services, and Sustainability'],
        ['office_code' => 'OESI',  'office_name' => 'Office of Extension Services & Societal Impact'],
        ['office_code' => 'OARE',  'office_name' => 'Office of Alumni Relations and Engagement'],
        ['office_code' => 'OIA',   'office_name' => 'Office of Internationalization Affairs'],
        ['office_code' => 'OATS',  'office_name' => 'Office of Admissions and Testing Services'],
        ['office_code' => 'SCH',   'office_name' => 'Schools'],
    ];

    public function run(): void
    {
        foreach (self::OFFICES as $office) {
            Office::updateOrCreate(
                ['office_code' => $office['office_code']],
                ['office_name' => $office['office_name'], 'is_active' => true]
            );
        }
    }
}
