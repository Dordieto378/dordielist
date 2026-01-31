<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL syntax; adjusts column to allow NULL and no default.
        DB::statement("ALTER TABLE `users` MODIFY `two_factor_confirmed_at` timestamp NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Revert to NOT NULL with current timestamp default (matches original migration)
        DB::statement("ALTER TABLE `users` MODIFY `two_factor_confirmed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
};
