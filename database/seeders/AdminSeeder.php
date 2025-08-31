<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('admins')->insert([
            'name' => 'Super Admin', // Replace with desired name
            'email' => 'admin@example.com', // Replace with desired email
            'password' => Hash::make('password123'), // Replace with desired password
            'status' => 'Active',
            'role' => 'Super Admin',
            'email_verified_at' => now(),
            'remember_token' => \Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
