<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        $office = Office::firstOrCreate(
            ['office_code' => 'HQ'],
            ['office_name' => 'Head Office']
        );

        $accounts = [
            [
                'full_name' => 'Systema Reyes',
                'email' => 'system.admin@example.test',
                'role' => User::ROLE_SYSTEM_ADMIN,
            ],
            [
                'full_name' => 'Osmund Cruz',
                'email' => 'osm.admin@example.test',
                'role' => User::ROLE_OSM_ADMIN,
            ],
            [
                'full_name' => 'Juana User',
                'email' => 'user@example.test',
                'role' => User::ROLE_USER,
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'full_name' => $account['full_name'],
                    'role' => $account['role'],
                    'office_id' => $office->id,
                    'is_active' => true,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
