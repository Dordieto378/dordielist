<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $adminRole = Role::firstOrCreate(['role' => 'Admin']);
            Role::firstOrCreate(['role' => 'Viewer']);

            // Imported or previously provisioned admins keep their credentials and status.
            if (User::where('role_id', $adminRole->role_id)->exists()) {
                return;
            }

            $credentials = [
                'email' => config('admin.email'),
                'username' => config('admin.username'),
                'password' => config('admin.password'),
            ];

            $validator = Validator::make($credentials, [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'username' => ['required', 'string', 'max:50', 'unique:users,username'],
                'password' => [
                    'required', 'string', 'max:72',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{15,}$/',
                ],
            ]);

            if ($validator->fails()) {
                throw new RuntimeException(
                    'Initial admin setup failed. Set ADMIN_EMAIL to an unused email, '
                    .'ADMIN_USERNAME to an unused username, and ADMIN_PASSWORD to a password '
                    .'of 15-72 characters containing uppercase, lowercase, a number, and a symbol. '
                    .'Existing non-admin accounts are never automatically promoted.'
                );
            }

            // User's password mutator hashes this once; do not pre-hash it here.
            User::create([
                ...$credentials,
                'role_id' => $adminRole->role_id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Preserve real accounts and roles if this data migration is rolled back.
    }
};
