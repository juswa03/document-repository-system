<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            LookupDataSeeder::class,
            OfficeSeeder::class,
            RoleUserSeeder::class,
            RequiredDocumentSeeder::class,
            StrategicObjectiveSeeder::class,
        ]);

        // Bulk demo data (20 users + an office admin per office + sample
        // submissions) — skipped outside local/testing so a production
        // seed run never populates fake accounts and documents.
        if (app()->environment(['local', 'testing'])) {
            $this->call(SampleDataSeeder::class);
        }
    }
}
