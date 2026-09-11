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
        // Assign test accounts to real offices seeded by OfficeSeeder. The
        // demo user and demo office admin share an office so a submission
        // from one always routes into the other's review queue out of the
        // box (system admin isn't office-scoped, so it can sit anywhere).
        $systemAdminOffice = Office::where('office_code', 'ODISM')->first();
        $sharedOffice      = Office::where('office_code', 'QAAO')->first();

        $accounts = [
            [
                'full_name' => 'Systema Reyes',
                'email'     => 'system.admin@example.test',
                'role'      => User::ROLE_SYSTEM_ADMIN,
                'office'    => $systemAdminOffice,
            ],
            [
                'full_name' => 'Osmund Cruz',
                'email'     => 'office.admin@example.test',
                'role'      => User::ROLE_OFFICE_ADMIN,
                'office'    => $sharedOffice,
            ],
            [
                'full_name' => 'Juana User',
                'email'     => 'user@example.test',
                'role'      => User::ROLE_USER,
                'office'    => $sharedOffice,
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'full_name'         => $account['full_name'],
                    'role'              => $account['role'],
                    'office_id'         => $account['office']?->id,
                    'is_active'         => true,
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
