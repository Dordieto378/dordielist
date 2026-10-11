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
            ], [
                'email.required' => 'ADMIN_EMAIL is missing.',
                'email.email' => 'ADMIN_EMAIL must be a valid email address.',
                'email.max' => 'ADMIN_EMAIL must not exceed 255 characters.',
                'email.unique' => 'ADMIN_EMAIL is already used by a non-admin account. Choose an unused email.',
                'username.required' => 'ADMIN_USERNAME is empty. Choose an unused username or omit it to use admin.',
                'username.string' => 'ADMIN_USERNAME must be a string.',
                'username.max' => 'ADMIN_USERNAME must not exceed 50 characters.',
                'username.unique' => 'ADMIN_USERNAME is already used by a non-admin account. Choose an unused username.',
                'password.required' => 'ADMIN_PASSWORD is missing.',
                'password.string' => 'ADMIN_PASSWORD must be a string.',
                'password.max' => 'ADMIN_PASSWORD must not exceed 72 characters.',
                'password.regex' => 'ADMIN_PASSWORD must contain at least 15 characters including uppercase, lowercase, a number, and a symbol.',
            ]);

            if ($validator->fails()) {
                throw new RuntimeException(
                    'Initial admin setup failed: '.implode(' ', $validator->errors()->all())
                    .' Existing non-admin accounts are never automatically promoted.'
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
