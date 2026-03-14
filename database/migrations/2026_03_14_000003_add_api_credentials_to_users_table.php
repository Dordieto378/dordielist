<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'anilist_access_token')) {
                $table->text('anilist_access_token')->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'vndb_api_token')) {
                $table->text('vndb_api_token')->nullable()->after('anilist_access_token');
            }
            if (!Schema::hasColumn('users', 'vndb_username')) {
                $table->string('vndb_username')->nullable()->after('vndb_api_token');
            }
            if (!Schema::hasColumn('users', 'vndb_password')) {
                $table->text('vndb_password')->nullable()->after('vndb_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['anilist_access_token', 'vndb_api_token', 'vndb_username', 'vndb_password'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
