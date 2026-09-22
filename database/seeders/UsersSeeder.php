<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'admin'],
            ['role' => 'admin', 'password' => Hash::make('change-me-admin')],
        );

        User::firstOrCreate(
            ['username' => 'user'],
            ['role' => 'user', 'password' => Hash::make('change-me-user')],
        );
    }
}
