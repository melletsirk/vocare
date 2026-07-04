<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@vocare.local'],
            [
                'name'     => 'Administrador',
                'dni'      => '00000000',
                'password' => Hash::make('Admin1234!'),
                'is_active' => true,
            ]
        );

        $admin->assignRole('admin_sistema');

        $this->command->info("✅ Usuario admin creado: admin@vocare.local / Admin1234!");
    }
}
