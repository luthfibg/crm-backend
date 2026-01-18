<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Create test users with known passwords
        \App\Models\User::create([
            'name' => 'Admin User',
            'email' => 'admin@mandiriartha.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role' => 'administrator',
            'phone_number' => '08123456789',
        ]);

        \App\Models\User::create([
            'name' => 'Sales User',
            'email' => 'sales@mandiriartha.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
            'role' => 'sales',
            'phone_number' => '08123456789',
        ]);
    }
}
