<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            if (!Schema::hasColumn('media', 'doujin_twitter_url')) {
                $table->string('doujin_twitter_url', 2048)->nullable()->after('cover_url');
            }

            if (!Schema::hasColumn('media', 'doujin_patreon_url')) {
                $table->string('doujin_patreon_url', 2048)->nullable()->after('doujin_twitter_url');
            }

            if (!Schema::hasColumn('media', 'doujin_fanbox_url')) {
                $table->string('doujin_fanbox_url', 2048)->nullable()->after('doujin_patreon_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            foreach (['doujin_fanbox_url', 'doujin_patreon_url', 'doujin_twitter_url'] as $column) {
                if (Schema::hasColumn('media', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
