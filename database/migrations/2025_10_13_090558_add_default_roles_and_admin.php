<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration {
    public function up(): void
    {
        // Ensure the roles table exists before inserting
        if (!Schema::hasTable('roles')) {
            return;
        }

        // Insert roles if they don't already exist
        DB::table('roles')->updateOrInsert(['role' => 'Admin']);
        DB::table('roles')->updateOrInsert(['role' => 'Viewer']);

        // Get the Admin role ID
        $adminRoleId = DB::table('roles')->where('role', 'Admin')->value('role_id');

        // Create default admin user if it doesn't exist
        $email = 'admin@dordielist.com';
        if (!DB::table('users')->where('email', $email)->exists()) {
            DB::table('users')->insert([
                'username'              => 'Admin',
                'email'                 => $email,
                'password'              => Hash::make('$WaQb%9D^&22a7Hf'),
                'email_verified_at'     => now(),
                'status'                => 'active',
                'role_id'               => $adminRoleId,
                'two_factor_secret'     => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed'  => false,
            ]);
        }
    }

    public function down(): void
    {
        // Remove the seeded data if needed
        DB::table('users')->where('email', 'admin@dordielist.com')->delete();
        DB::table('roles')->whereIn('role', ['Admin', 'Viewer'])->delete();
    }
};
