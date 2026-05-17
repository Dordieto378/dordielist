<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url'] as $column) {
            if (Schema::hasColumn('doujin_authors', $column)) {
                DB::statement("ALTER TABLE doujin_authors MODIFY {$column} TEXT NULL");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url'] as $column) {
            if (Schema::hasColumn('doujin_authors', $column)) {
                DB::statement("ALTER TABLE doujin_authors MODIFY {$column} VARCHAR(2048) NULL");
            }
        }
    }
};
