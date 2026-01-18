<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create test users with known passwords
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@mandiriartha.com',
            'password' => Hash::make('password123'),
            'role' => 'administrator',
            'phone_number' => '08123456789',
        ]);

        User::create([
            'name' => 'Sales User',
            'email' => 'sales@mandiriartha.com',
            'password' => Hash::make('password123'),
            'role' => 'sales',
            'phone_number' => '08123456789',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        User::where('email', 'admin@mandiriartha.com')->delete();
        User::where('email', 'sales@mandiriartha.com')->delete();
    }
};
