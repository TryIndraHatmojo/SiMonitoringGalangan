<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'demo@gmail.com'],
            [
                'name' => 'Demo Monitoring Galangan',
                'email_verified_at' => now(),
                'password' => 'Merdeka1945!!!',
            ],
        );
    }
}
