<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media', function (Blueprint $t) {
            // If columns don't exist:
            if (!Schema::hasColumn('media', 'genres')) $t->json('genres')->nullable()->after('alt_title');
            if (!Schema::hasColumn('media', 'tags'))   $t->json('tags')->nullable()->after('genres');

            // If they already exist as TEXT/VARCHAR, change them to JSON (MySQL 5.7+/MariaDB 10.2.7+)
            // $t->json('genres')->nullable()->change();
            // $t->json('tags')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $t) {
            $t->dropColumn(['genres','tags']);
        });
    }
};